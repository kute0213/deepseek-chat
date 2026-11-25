<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;
use App\Support\Snowflake;

final class ImportService
{
    /** @return array{sessions: array<int, array>, nextCursor: ?string, total: int} 新会话首页摘要 */
    public static function commit(string $userId): array
    {
        set_time_limit(0);
        ignore_user_abort(true);
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, data, created_ms FROM staging_sessions WHERE user_id = ? ORDER BY created_ms ASC');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $limit = (int) SiteSettings::get('max_sessions_per_user', '0');
        if ($limit > 0 && count($rows) > $limit) {
            throw new HttpError('导入会话数量超过站点上限（' . $limit . '）', 422);
        }

        $parsed = [];
        $messageTotal = 0;
        foreach ($rows as $row) {
            $session = json_decode((string) $row['data'], true);
            if (!is_array($session) || !isset($session['messages']) || !is_array($session['messages'])) {
                throw new HttpError('导入暂存数据损坏，请重新导入', 422);
            }
            $parsed[] = ['session' => $session, 'created_ms' => (int) $row['created_ms']];
            $messageTotal += count($session['messages']);
        }

        $pool = ($parsed === [] ? [] : Snowflake::instance()->nextBatch(count($parsed) + $messageTotal));
        $cursor = 0;

        $prepared = [];
        foreach ($parsed as $item) {
            $session = $item['session'];
            $newSessionId = $pool[$cursor++];
            $root = $session['rootMessageId'] ?? null;
            $root = ($root !== null && $root !== '') ? (string) $root : null;
            $session['messages'] = self::remapMessages($session['messages'], $pool, $cursor, $root);
            $session['id'] = $newSessionId;
            $session['rootMessageId'] = $root;

            $now = (int) floor(microtime(true) * 1000);
            $createdAt = (int) ($session['createdAt'] ?? $item['created_ms']);
            if ($createdAt < 946684800000 || $createdAt > $now + 86400000) {
                $createdAt = $item['created_ms'];
            }
            $updatedAt = (int) ($session['updatedAt'] ?? $createdAt);
            if ($updatedAt < $createdAt || $updatedAt > $now + 86400000) {
                $updatedAt = $createdAt;
            }
            $session['createdAt'] = $createdAt;
            $session['updatedAt'] = $updatedAt;
            $session['title'] = mb_substr((string) ($session['title'] ?? ''), 0, 200);

            $messagesCount = count($session['messages']);
            [$inputTokens, $outputTokens] = self::usage($session['messages']);
            $fileIds = self::fileIds($session['messages']);
            $data = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($data === false) {
                throw new HttpError('导入会话序列化失败', 422);
            }
            $prepared[] = [
                'id' => $newSessionId,
                'title' => $session['title'],
                'root' => $session['rootMessageId'],
                'messages' => $messagesCount,
                'input' => $inputTokens,
                'output' => $outputTokens,
                'data' => $data,
                'created' => $createdAt,
                'updated' => $updatedAt,
                'files' => $fileIds,
            ];
        }

        $stmt = $pdo->prepare('SELECT id, name, size, is_image, mime_type, created_ms FROM staging_files WHERE user_id = ?');
        $stmt->execute([$userId]);
        $stagingFiles = $stmt->fetchAll();

        $uploadDir = FileStore::attachmentDir($userId);
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new HttpError('无法创建附件目录', 500);
        }
        foreach ($stagingFiles as $file) {
            $src = FileStore::stagingDir($userId) . '/' . $file['id'];
            if (!is_file($src)) {
                continue;
            }
            $target = $uploadDir . '/' . $file['id'];
            if (is_file($target)) {
                @unlink($target);
            }
            if (!rename($src, $target)) {
                throw new HttpError('导入附件移动失败', 500);
            }
        }

        $stmt = $pdo->prepare('SELECT id FROM files WHERE user_id = ?');
        $stmt->execute([$userId]);
        $oldFileIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        Database::tx(function () use ($pdo, $userId, $prepared, $stagingFiles): void {
            SessionStore::archiveAll($userId);
            $stmt = $pdo->prepare('DELETE FROM session_files WHERE user_id = ?');
            $stmt->execute([$userId]);
            $stmt = $pdo->prepare('DELETE FROM files WHERE user_id = ?');
            $stmt->execute([$userId]);
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = ?');
            $stmt->execute([$userId]);

            $insertSession = $pdo->prepare(
                'INSERT INTO sessions (id, user_id, title, root_message_id, messages_count, input_tokens, output_tokens, data, created_ms, updated_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertRef = $pdo->prepare('INSERT IGNORE INTO session_files (user_id, session_id, file_id) VALUES (?, ?, ?)');
            foreach ($prepared as $row) {
                $insertSession->execute([
                    $row['id'],
                    $userId,
                    $row['title'],
                    $row['root'],
                    $row['messages'],
                    $row['input'],
                    $row['output'],
                    $row['data'],
                    $row['created'],
                    $row['updated'],
                ]);
                foreach ($row['files'] as $fileId) {
                    $insertRef->execute([$userId, $row['id'], $fileId]);
                }
            }

            $insertFile = $pdo->prepare(
                'INSERT INTO files (user_id, id, name, size, is_image, mime_type, server_id, image_size_checked, created_ms)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, 0, ?)'
            );
            foreach ($stagingFiles as $file) {
                $insertFile->execute([
                    $userId,
                    $file['id'],
                    $file['name'],
                    $file['size'],
                    $file['is_image'],
                    $file['mime_type'],
                    $file['created_ms'],
                ]);
            }

            $stmt = $pdo->prepare('DELETE FROM staging_sessions WHERE user_id = ?');
            $stmt->execute([$userId]);
            $stmt = $pdo->prepare('DELETE FROM staging_files WHERE user_id = ?');
            $stmt->execute([$userId]);
        });

        $newFileIds = array_fill_keys(array_map(static fn (array $f): string => (string) $f['id'], $stagingFiles), true);
        foreach ($oldFileIds as $oldId) {
            if (!isset($newFileIds[(string) $oldId])) {
                FileStore::removeFile($userId, (string) $oldId);
            }
        }
        FileStore::removeDir(FileStore::stagingDir($userId));

        return SessionStore::summaries($userId, 30) + ['total' => count($prepared)];
    }

    /**
     * 将归档中的消息 ID 按父节点优先顺序重编号为雪花。
     */
    private static function remapMessages(array $messages, array $pool, int &$cursor, ?string &$rootMessageId): array
    {
        $byId = [];
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                throw new HttpError('导入消息格式错误', 422);
            }
            $oldId = (string) ($message['id'] ?? '');
            if ($oldId === '') {
                throw new HttpError('导入消息缺少 ID', 422);
            }
            $byId[$oldId] = $index;
        }

        $state = [];
        $order = [];
        $visit = function (string $id) use (&$visit, &$byId, &$state, &$order, $messages): void {
            $current = $state[$id] ?? 0;
            if ($current === 2) {
                return;
            }
            if ($current === 1) {
                throw new HttpError('导入消息树存在循环', 422);
            }
            if (!isset($byId[$id])) {
                throw new HttpError('导入消息缺少父节点', 422);
            }
            $state[$id] = 1;
            $index = $byId[$id];
            $parent = (string) ($messages[$index]['parentId'] ?? '');
            if ($parent !== '') {
                $visit($parent);
            }
            $state[$id] = 2;
            $order[] = $index;
        };
        foreach (array_keys($byId) as $id) {
            $visit((string) $id);
        }

        $map = [];
        foreach ($order as $index) {
            $map[(string) $messages[$index]['id']] = $pool[$cursor++];
        }
        if ($rootMessageId !== null) {
            $rootMessageId = $map[$rootMessageId] ?? null;
        }
        foreach ($order as $index) {
            $message = &$messages[$index];
            $message['id'] = $map[(string) $message['id']];
            if (isset($message['parentId']) && $message['parentId'] !== null && $message['parentId'] !== '') {
                $message['parentId'] = $map[(string) $message['parentId']];
            } else {
                unset($message['parentId']);
            }
            if (isset($message['selectedChildId']) && $message['selectedChildId'] !== null && $message['selectedChildId'] !== '') {
                $message['selectedChildId'] = $map[(string) $message['selectedChildId']] ?? null;
            } else {
                unset($message['selectedChildId']);
            }
            unset($message);
        }

        return $messages;
    }

    private static function usage(array $messages): array
    {
        $input = 0;
        $output = 0;
        foreach ($messages as $message) {
            if (is_array($message) && ($message['role'] ?? '') === 'assistant' && isset($message['usage']) && is_array($message['usage'])) {
                $usage = $message['usage'];
                $input += (int) ($usage['input_tokens'] ?? 0)
                    + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                    + (int) ($usage['cache_read_input_tokens'] ?? 0);
                $output += (int) ($usage['output_tokens'] ?? 0);
            }
        }
        return [$input, $output];
    }

    /** @return string[] */
    private static function fileIds(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            if (!is_array($message) || ($message['role'] ?? '') !== 'user' || !isset($message['files']) || !is_array($message['files'])) {
                continue;
            }
            foreach ($message['files'] as $file) {
                $fileId = is_array($file) ? (string) ($file['id'] ?? '') : '';
                if ($fileId !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $fileId)) {
                    $ids[$fileId] = true;
                }
            }
        }
        return array_keys($ids);
    }
}
