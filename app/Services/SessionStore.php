<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;
use PDO;

final class SessionStore
{
    /**
     * 按更新时间倒序分页返回会话摘要，配合前端滚动加载，避免一次拉取全部记录。
     *
     * @return array{sessions: array<int, array>, nextCursor: ?string}
     */
    public static function summaries(
        string $userId,
        int $limit = 30,
        ?int $beforeUpdated = null,
        ?string $beforeId = null
    ): array {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT id, title, created_ms, updated_ms FROM sessions WHERE user_id = ?';
        $args = [$userId];
        // 键集分页：用 (updated_ms, id) 作为游标，避免数据变动时 OFFSET 跳页或重复
        if ($beforeUpdated !== null && $beforeId !== null) {
            $sql .= ' AND (updated_ms < ? OR (updated_ms = ? AND id < ?))';
            $args[] = $beforeUpdated;
            $args[] = $beforeUpdated;
            $args[] = $beforeId;
        }
        $sql .= ' ORDER BY updated_ms DESC, id DESC LIMIT ' . ($limit + 1);
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $sessions = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'createdAt' => (int) $row['created_ms'],
            'updatedAt' => (int) $row['updated_ms'],
        ], $rows);
        $nextCursor = null;
        if ($hasMore && $sessions !== []) {
            $last = $sessions[count($sessions) - 1];
            $nextCursor = $last['updatedAt'] . '_' . $last['id'];
        }
        return ['sessions' => $sessions, 'nextCursor' => $nextCursor];
    }

    public static function load(string $userId, string $sessionId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT data FROM sessions WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $sessionId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        return is_array($data) ? $data : null;
    }

    public static function save(string $userId, string $sessionId, array $session): void
    {
        if (!preg_match('/^[0-9]{1,20}$/', $sessionId)) {
            throw new HttpError('会话 ID 非法', 400);
        }
        if (($session['id'] ?? null) !== $sessionId) {
            throw new HttpError('会话 ID 不匹配', 400);
        }
        if (!isset($session['messages']) || !is_array($session['messages'])) {
            throw new HttpError('会话数据不完整', 422);
        }

        $now = (int) floor(microtime(true) * 1000);
        $createdAt = (int) ($session['createdAt'] ?? $now);
        if ($createdAt < 946684800000 || $createdAt > $now + 86400000) {
            $createdAt = $now;
        }
        $updatedAt = (int) ($session['updatedAt'] ?? $now);
        if ($updatedAt < $createdAt || $updatedAt > $now + 86400000) {
            $updatedAt = $now;
        }
        $title = mb_substr((string) ($session['title'] ?? ''), 0, 200);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT user_id FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $existing = $stmt->fetch();
        if ($existing && (string) $existing['user_id'] !== $userId) {
            throw new HttpError('会话不存在', 404);
        }
        if (!$existing) {
            $limit = (int) SiteSettings::get('max_sessions_per_user', '0');
            if ($limit > 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ?');
                $stmt->execute([$userId]);
                if ((int) $stmt->fetchColumn() >= $limit) {
                    throw new HttpError('会话数量已达上限', 422);
                }
            }
        }

        $messagesCount = count($session['messages']);
        $inputTokens = 0;
        $outputTokens = 0;
        $fileIds = [];
        foreach ($session['messages'] as $message) {
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? '') === 'assistant' && isset($message['usage']) && is_array($message['usage'])) {
                $usage = $message['usage'];
                $inputTokens += (int) ($usage['input_tokens'] ?? 0)
                    + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                    + (int) ($usage['cache_read_input_tokens'] ?? 0);
                $outputTokens += (int) ($usage['output_tokens'] ?? 0);
            }
            if (($message['role'] ?? '') === 'user' && isset($message['files']) && is_array($message['files'])) {
                foreach ($message['files'] as $file) {
                    $fileId = is_array($file) ? (string) ($file['id'] ?? '') : '';
                    if ($fileId !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $fileId)) {
                        $fileIds[$fileId] = true;
                    }
                }
            }
        }

        $rootMessageId = $session['rootMessageId'] ?? null;
        $rootMessageId = is_string($rootMessageId) && preg_match('/^[0-9]{1,20}$/', $rootMessageId)
            ? $rootMessageId
            : null;

        $data = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($data === false) {
            throw new HttpError('会话数据序列化失败', 422);
        }

        Database::tx(function () use ($pdo, $userId, $sessionId, $title, $rootMessageId, $messagesCount, $inputTokens, $outputTokens, $data, $createdAt, $updatedAt, $fileIds): void {
            $stmt = $pdo->prepare(
                'INSERT INTO sessions (id, user_id, title, root_message_id, messages_count, input_tokens, output_tokens, data, created_ms, updated_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE title = VALUES(title), root_message_id = VALUES(root_message_id),
                   messages_count = VALUES(messages_count), input_tokens = VALUES(input_tokens),
                   output_tokens = VALUES(output_tokens), data = VALUES(data), updated_ms = VALUES(updated_ms)'
            );
            $stmt->execute([
                $sessionId,
                $userId,
                $title,
                $rootMessageId,
                $messagesCount,
                $inputTokens,
                $outputTokens,
                $data,
                $createdAt,
                $updatedAt,
            ]);

            $stmt = $pdo->prepare('DELETE FROM session_files WHERE user_id = ? AND session_id = ?');
            $stmt->execute([$userId, $sessionId]);
            if ($fileIds !== []) {
                $insert = $pdo->prepare('INSERT IGNORE INTO session_files (user_id, session_id, file_id) VALUES (?, ?, ?)');
                foreach (array_keys($fileIds) as $fileId) {
                    $insert->execute([$userId, $sessionId, $fileId]);
                }
            }
        });

        self::cleanupOrphans($userId);
    }

    public static function rename(string $userId, string $sessionId, string $title): void
    {
        $stmt = Database::pdo()->prepare('UPDATE sessions SET title = ?, updated_ms = ? WHERE user_id = ? AND id = ?');
        $stmt->execute([mb_substr(trim($title), 0, 200), (int) floor(microtime(true) * 1000), $userId, $sessionId]);
    }

    public static function delete(string $userId, string $sessionId): void
    {
        Database::tx(function () use ($userId, $sessionId): void {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('DELETE FROM session_files WHERE user_id = ? AND session_id = ?');
            $stmt->execute([$userId, $sessionId]);
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND id = ?');
            $stmt->execute([$userId, $sessionId]);
        });
        self::cleanupOrphans($userId);
    }

    public static function cleanupOrphans(string $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT f.id FROM files f
             LEFT JOIN session_files sf ON sf.user_id = f.user_id AND sf.file_id = f.id
             WHERE f.user_id = ? AND sf.file_id IS NULL'
        );
        $stmt->execute([$userId]);
        $orphans = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($orphans === []) {
            return;
        }
        $delete = Database::pdo()->prepare('DELETE FROM files WHERE user_id = ? AND id = ?');
        foreach ($orphans as $fileId) {
            $delete->execute([$userId, $fileId]);
            FileStore::removeFile($userId, (string) $fileId);
        }
    }
}
