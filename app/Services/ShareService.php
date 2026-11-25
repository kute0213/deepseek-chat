<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;
use App\Support\Snowflake;

/**
 * 会话分享：创建时把当前可见分支的消息保存为快照，公开页只读展示；
 * 分享页的「继续对话」会把快照复制成访问者自己的新会话。
 */
final class ShareService
{
    private static bool $ready = false;

    /** 已安装站点没有迁移脚本，首次调用时按需建表。 */
    private static function ensureTable(): void
    {
        if (self::$ready) {
            return;
        }
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS share_links (
                token CHAR(32) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                session_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT \'\',
                data LONGTEXT NOT NULL,
                views INT NOT NULL DEFAULT 0,
                revoked TINYINT NOT NULL DEFAULT 0,
                created_ms BIGINT NOT NULL,
                expires_ms BIGINT NULL,
                PRIMARY KEY (token),
                KEY idx_shares_user (user_id, created_ms),
                KEY idx_shares_session (session_id),
                KEY idx_shares_created (created_ms)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$ready = true;
    }

    /** 取当前选中分支的可见消息，并按选中的消息 ID 过滤（含其后的回答）。 */
    private static function snapshot(array $session, array $messageIds = []): array
    {
        $messages = array_values(array_filter(
            is_array($session['messages'] ?? null) ? $session['messages'] : [],
            static fn ($message): bool => is_array($message)
        ));
        // 旧版线性会话没有 rootMessageId，直接按存储顺序输出
        if (!array_key_exists('rootMessageId', $session)) {
            $ordered = $messages;
        } else {
            $byId = [];
            foreach ($messages as $message) {
                if (isset($message['id'])) {
                    $byId[(string) $message['id']] = $message;
                }
            }
            $root = $session['rootMessageId'] ?? null;
            if (!is_string($root) || $root === '') {
                $root = isset($messages[0]['id']) ? (string) $messages[0]['id'] : null;
            }
            $ordered = [];
            while ($root !== null && isset($byId[$root])) {
                $message = $byId[$root];
                unset($byId[$root]);
                $ordered[] = $message;
                $next = $message['selectedChildId'] ?? null;
                $root = is_string($next) && $next !== '' ? $next : null;
            }
        }
        $selected = array_fill_keys(array_map('strval', $messageIds), true);
        $path = [];
        $includeNext = false;
        foreach ($ordered as $message) {
            $id = (string) ($message['id'] ?? '');
            $take = $selected === [] || isset($selected[$id]) || ($includeNext && ($message['role'] ?? '') === 'assistant');
            $includeNext = isset($selected[$id]);
            if ($take) {
                $path[] = self::publicMessage($message);
            }
        }
        return ['messages' => $path];
    }

    /** 公开消息只保留渲染所需字段：提问文本、附件名称与回答正文。 */
    private static function publicMessage(array $message): array
    {
        $role = ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $output = [
            'id' => (string) ($message['id'] ?? ''),
            'role' => $role,
            'createdAt' => (int) ($message['createdAt'] ?? 0),
        ];
        if ($role === 'user') {
            $output['content'] = (string) ($message['content'] ?? '');
            $files = [];
            foreach (is_array($message['files'] ?? null) ? $message['files'] : [] as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $files[] = [
                    'name' => (string) ($file['name'] ?? ''),
                    'size' => (int) ($file['size'] ?? 0),
                    'isImage' => !empty($file['isImage']),
                ];
            }
            $output['files'] = $files;
            return $output;
        }
        $texts = [];
        foreach (is_array($message['blocks'] ?? null) ? $message['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $texts[] = (string) $block['text'];
            }
        }
        $output['text'] = implode("\n\n", $texts);
        return $output;
    }

    public static function create(string $userId, string $sessionId, array $messageIds = [], int $ttlDays = 0): array
    {
        self::ensureTable();
        $session = SessionStore::load($userId, $sessionId);
        if ($session === null) {
            throw new HttpError('会话不存在', 404);
        }
        $messageIds = array_values(array_filter(array_map('strval', $messageIds), static fn ($id): bool => $id !== ''));
        if ($messageIds === []) {
            throw new HttpError('请选择要分享的消息', 422);
        }
        $snapshot = self::snapshot($session, $messageIds);
        if ($snapshot['messages'] === []) {
            throw new HttpError('所选消息不存在', 422);
        }
        $title = mb_substr(trim((string) ($session['title'] ?? '')), 0, 200);
        $now = (int) floor(microtime(true) * 1000);
        $token = bin2hex(random_bytes(16));
        $data = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($data === false) {
            throw new HttpError('分享数据序列化失败', 422);
        }
        $expires = $ttlDays > 0 ? $now + $ttlDays * 86400000 : null;
        $stmt = Database::pdo()->prepare(
            'INSERT INTO share_links (token, user_id, session_id, title, data, views, revoked, created_ms, expires_ms)
             VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)'
        );
        $stmt->execute([$token, $userId, $sessionId, $title, $data, $now, $expires]);
        return [
            'token' => $token,
            'title' => $title,
            'sessionId' => $sessionId,
            'createdAt' => $now,
            'expiresAt' => $expires,
            'url' => '/?share=' . $token,
        ];
    }

    public static function listMine(string $userId): array
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare(
            'SELECT token, session_id, title, views, revoked, created_ms, expires_ms
             FROM share_links WHERE user_id = ? ORDER BY created_ms DESC LIMIT 200'
        );
        $stmt->execute([$userId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = self::summary($row);
        }
        return ['shares' => $rows];
    }

    /** 撤销分享：直接删除记录，不留历史痕迹 */
    public static function revoke(string $userId, string $token): void
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare('DELETE FROM share_links WHERE token = ? AND user_id = ?');
        $stmt->execute([$token, $userId]);
    }

    /** 公开读取：失效、撤销、过期都按 404 处理，并累加访问次数。 */
    public static function publicView(string $token): array
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare(
            'SELECT s.token, s.title, s.data, s.revoked, s.created_ms, s.expires_ms, u.username
             FROM share_links s JOIN users u ON u.id = s.user_id
             WHERE s.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        $now = (int) floor(microtime(true) * 1000);
        if (!$row || (int) $row['revoked'] === 1) {
            throw new HttpError('分享不存在或已失效', 404);
        }
        if ($row['expires_ms'] !== null && (int) $row['expires_ms'] <= $now) {
            throw new HttpError('分享已过期', 404);
        }
        Database::pdo()
            ->prepare('UPDATE share_links SET views = views + 1 WHERE token = ?')
            ->execute([$token]);
        $data = json_decode((string) $row['data'], true);
        return [
            'token' => (string) $row['token'],
            'title' => (string) $row['title'],
            'owner' => (string) $row['username'],
            'createdAt' => (int) $row['created_ms'],
            'messages' => is_array($data['messages'] ?? null) ? $data['messages'] : [],
        ];
    }

    /** 分享页「继续对话」：把快照复制成访问者自己的新会话，返回新会话 ID。 */
    public static function fork(string $userId, string $token): array
    {
        $share = self::publicView($token);
        $messages = [];
        $parentId = null;
        $count = count($share['messages']);
        $ids = Snowflake::instance()->nextBatch(max(1, $count));
        foreach ($share['messages'] as $index => $message) {
            $id = (string) $ids[$index];
            $item = [
                'id' => $id,
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'createdAt' => (int) ($message['createdAt'] ?? 0) ?: (int) floor(microtime(true) * 1000),
                'parentId' => $parentId,
            ];
            if ($item['role'] === 'user') {
                $item['content'] = (string) ($message['content'] ?? '');
                $item['files'] = [];
            } else {
                $text = (string) ($message['text'] ?? '');
                $item['model'] = 'deepseek-flash';
                $item['thinking'] = false;
                $item['search'] = false;
                $item['prefix'] = false;
                $item['status'] = 'done';
                $item['blocks'] = $text === '' ? [] : [['type' => 'text', 'text' => $text]];
            }
            if ($parentId !== null) {
                $messages[count($messages) - 1]['selectedChildId'] = $id;
            }
            $messages[] = $item;
            $parentId = $id;
        }
        $now = (int) floor(microtime(true) * 1000);
        $sessionId = (string) Snowflake::instance()->nextId();
        $session = [
            'id' => $sessionId,
            'title' => $share['title'] !== '' ? $share['title'] : '分享的对话',
            'createdAt' => $now,
            'updatedAt' => $now,
            'messages' => $messages,
            'rootMessageId' => $messages[0]['id'] ?? null,
        ];
        SessionStore::save($userId, $sessionId, $session);
        return [
            'sessionId' => $sessionId,
            'title' => (string) ($session['title'] ?? ''),
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
    }

    /* ---------------------------------------------------------------- 管理后台 */

    public static function listAll(string $q, int $page, int $perPage): array
    {
        self::ensureTable();
        $pdo = Database::pdo();
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = ' WHERE s.title LIKE ? OR u.username LIKE ? OR s.token LIKE ? OR CAST(s.session_id AS CHAR) LIKE ?';
            $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM share_links s JOIN users u ON u.id = s.user_id' . $where
        );
        $stmt->execute($params);
        $meta = AdminService::page((int) $stmt->fetchColumn(), $perPage, $page);
        $stmt = $pdo->prepare(
            'SELECT s.token, s.session_id, s.title, s.views, s.revoked, s.created_ms, s.expires_ms,
                    u.username, u.id AS user_id
             FROM share_links s JOIN users u ON u.id = s.user_id' . $where . '
             ORDER BY s.created_ms DESC LIMIT ' . $perPage . ' OFFSET ' . $meta['offset']
        );
        $stmt->execute($params);
        $meta['rows'] = $stmt->fetchAll();
        return $meta;
    }

    public static function setRevoked(string $token, bool $revoked): void
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare('UPDATE share_links SET revoked = ? WHERE token = ?');
        $stmt->execute([$revoked ? 1 : 0, $token]);
    }

    public static function delete(string $token): void
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare('DELETE FROM share_links WHERE token = ?');
        $stmt->execute([$token]);
    }

    /** 账号注销时一并清理其分享链接 */
    public static function deleteByUser(string $userId): void
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare('DELETE FROM share_links WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    public static function stats(): array
    {
        self::ensureTable();
        $row = Database::pdo()
            ->query('SELECT COUNT(*) AS total, COALESCE(SUM(revoked = 0), 0) AS active, COALESCE(SUM(views), 0) AS views FROM share_links')
            ->fetch();
        return [
            'total' => (int) $row['total'],
            'active' => (int) $row['active'],
            'views' => (int) $row['views'],
        ];
    }

    private static function summary(array $row): array
    {
        return [
            'token' => (string) $row['token'],
            'sessionId' => (string) $row['session_id'],
            'title' => (string) $row['title'],
            'views' => (int) $row['views'],
            'revoked' => (int) $row['revoked'] === 1,
            'createdAt' => (int) $row['created_ms'],
            'expiresAt' => $row['expires_ms'] !== null ? (int) $row['expires_ms'] : null,
            'url' => '/?share=' . $row['token'],
        ];
    }
}
