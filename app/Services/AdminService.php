<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Snowflake;

/** 后台管理的数据查询与写操作；所有写操作由 admin/index.php 记录审计日志 */
final class AdminService
{
    public static function stats(): array
    {
        $pdo = Database::pdo();
        $users = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(status = 1), 0) AS active FROM users')->fetch();
        $sessions = $pdo->query(
            'SELECT COUNT(*) AS total, COALESCE(SUM(messages_count), 0) AS messages,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens
             FROM sessions'
        )->fetch();
        $files = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(size), 0) AS bytes FROM files')->fetch();
        $shares = ShareService::stats();
        $archivedInput = 0;
        $archivedOutput = 0;
        try {
            $archived = $pdo->query(
                'SELECT COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens FROM usage_archives'
            )->fetch();
            $archivedInput = (int) ($archived['input_tokens'] ?? 0);
            $archivedOutput = (int) ($archived['output_tokens'] ?? 0);
        } catch (\Throwable) {
            // 旧库未建归档表时按当前会话统计
        }

        return [
            'users' => (int) $users['total'],
            'activeUsers' => (int) $users['active'],
            'sessions' => (int) $sessions['total'],
            'messages' => (int) $sessions['messages'],
            'inputTokens' => (int) $sessions['input_tokens'] + $archivedInput,
            'outputTokens' => (int) $sessions['output_tokens'] + $archivedOutput,
            'files' => (int) $files['total'],
            'fileBytes' => (int) $files['bytes'],
            'shares' => $shares['total'],
            'activeShares' => $shares['active'],
            'shareViews' => $shares['views'],
        ];
    }

    /** 统一分页：返回当前页码、总页数与 SQL 偏移 */
    public static function page(int $total, int $perPage, int $page): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'total' => $total];
    }

    public static function listUsers(string $q, int $page, int $perPage): array
    {
        $pdo = Database::pdo();
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = ' WHERE username LIKE ? OR CAST(id AS CHAR) LIKE ?';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users' . $where);
        $stmt->execute($params);
        $meta = self::page((int) $stmt->fetchColumn(), $perPage, $page);
        $stmt = $pdo->prepare(
            'SELECT id, username, role, status, api_key, created_ms, last_login_ms,
                    (SELECT COUNT(*) FROM sessions s WHERE s.user_id = users.id) AS sessions_count
             FROM users' . $where . ' ORDER BY created_ms DESC LIMIT ' . $perPage . ' OFFSET ' . $meta['offset']
        );
        $stmt->execute($params);
        $meta['rows'] = $stmt->fetchAll();
        return $meta;
    }

    /** 单个用户的完整资料：账号字段（含明文 API Key、密码哈希）、统计、最近会话与附件 */
    public static function userDetail(string $userId): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(messages_count), 0) AS messages,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens
             FROM sessions WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $sessions = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(size), 0) AS bytes FROM files WHERE user_id = ?');
        $stmt->execute([$userId]);
        $files = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        $tokens = (int) $stmt->fetchColumn();

        $archivedInput = 0;
        $archivedOutput = 0;
        try {
            $stmt = $pdo->prepare(
                'SELECT input_tokens, output_tokens FROM usage_archives WHERE user_id = ?'
            );
            $stmt->execute([$userId]);
            $archived = $stmt->fetch();
            if ($archived !== false) {
                $archivedInput = (int) $archived['input_tokens'];
                $archivedOutput = (int) $archived['output_tokens'];
            }
        } catch (\Throwable) {
            // 旧库未建归档表时按当前会话统计
        }

        $stmt = $pdo->prepare(
            'SELECT id, title, messages_count, input_tokens, output_tokens, created_ms, updated_ms
             FROM sessions WHERE user_id = ? ORDER BY updated_ms DESC LIMIT 50'
        );
        $stmt->execute([$userId]);
        $sessionRows = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT id, name, size, is_image, mime_type, created_ms
             FROM files WHERE user_id = ? ORDER BY created_ms DESC LIMIT 50'
        );
        $stmt->execute([$userId]);
        $fileRows = $stmt->fetchAll();

        return [
            'user' => $user,
            'stats' => [
                'sessions' => (int) $sessions['total'],
                'messages' => (int) $sessions['messages'],
                'inputTokens' => (int) $sessions['input_tokens'] + $archivedInput,
                'outputTokens' => (int) $sessions['output_tokens'] + $archivedOutput,
                'files' => (int) $files['total'],
                'fileBytes' => (int) $files['bytes'],
                'tokens' => $tokens,
            ],
            'sessions' => $sessionRows,
            'files' => $fileRows,
        ];
    }

    public static function createUser(string $username, string $password, string $role): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            throw new HttpError('用户名需为 3-32 位字母、数字或下划线', 422);
        }
        if (strlen($password) < 8) {
            throw new HttpError('密码至少 8 位', 422);
        }
        if (UserService::findByUsername($username) !== null) {
            throw new HttpError('用户名已存在', 409);
        }
        $now = (int) floor(microtime(true) * 1000);
        $id = Snowflake::instance()->nextId();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (id, username, password_hash, role, status, created_ms, updated_ms) VALUES (?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([$id, $username, Auth::passwordHash($password), $role === 'admin' ? 'admin' : 'user', $now, $now]);
    }

    public static function setRole(string $userId, string $role): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET role = ?, updated_ms = ? WHERE id = ?');
        $stmt->execute([$role === 'admin' ? 'admin' : 'user', (int) floor(microtime(true) * 1000), $userId]);
    }

    public static function setStatus(string $userId, bool $enabled): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET status = ?, updated_ms = ? WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, (int) floor(microtime(true) * 1000), $userId]);
        if (!$enabled) {
            $stmt = Database::pdo()->prepare('DELETE FROM auth_tokens WHERE user_id = ?');
            $stmt->execute([$userId]);
        }
    }

    public static function deleteUser(string $userId): void
    {
        $pdo = Database::pdo();
        Database::tx(function () use ($pdo, $userId): void {
            foreach (['auth_tokens', 'sessions', 'files', 'session_files', 'staging_sessions', 'staging_files'] as $table) {
                $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id = ?');
                $stmt->execute([$userId]);
            }
            ShareService::deleteByUser($userId);
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
        });
        FileStore::removeDir(FileStore::attachmentDir($userId));
        FileStore::removeDir(FileStore::stagingDir($userId));
    }

    public static function listSessions(string $q, int $page, int $perPage): array
    {
        $pdo = Database::pdo();
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = ' WHERE s.title LIKE ? OR u.username LIKE ? OR CAST(s.id AS CHAR) LIKE ?';
            $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM sessions s JOIN users u ON u.id = s.user_id' . $where
        );
        $stmt->execute($params);
        $meta = self::page((int) $stmt->fetchColumn(), $perPage, $page);
        $stmt = $pdo->prepare(
            'SELECT s.id, s.title, s.messages_count, s.input_tokens, s.output_tokens, s.created_ms, s.updated_ms,
                    u.username, u.id AS user_id
             FROM sessions s JOIN users u ON u.id = s.user_id' . $where . '
             ORDER BY s.updated_ms DESC LIMIT ' . $perPage . ' OFFSET ' . $meta['offset']
        );
        $stmt->execute($params);
        $meta['rows'] = $stmt->fetchAll();
        return $meta;
    }

    public static function getSession(string $sessionId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.*, u.username FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ?'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['data'] = json_decode((string) $row['data'], true) ?: [];
        return $row;
    }

    public static function deleteSession(string $sessionId): void
    {
        $stmt = Database::pdo()->prepare('SELECT user_id FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            throw new HttpError('会话不存在', 404);
        }
        SessionStore::delete((string) $userId, $sessionId);
    }
}
