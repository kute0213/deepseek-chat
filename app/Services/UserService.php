<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Request;
use App\Support\Snowflake;

final class UserService
{
    public static function findById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $username, string $password): array
    {
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            throw new HttpError('用户名需为 3-32 位字母、数字或下划线', 422);
        }
        if (strlen($password) < 8) {
            throw new HttpError('密码至少 8 位', 422);
        }
        if (self::findByUsername($username) !== null) {
            throw new HttpError('用户名已存在', 409);
        }

        $now = (int) floor(microtime(true) * 1000);
        $id = Snowflake::instance()->nextId();
        $stmt = Database::pdo()->prepare(
            "INSERT INTO users (id, username, password_hash, role, status, created_ms, updated_ms)
             VALUES (?, ?, ?, 'user', 1, ?, ?)"
        );
        $stmt->execute([$id, $username, Auth::passwordHash($password), $now, $now]);

        return self::findById($id) ?? throw new HttpError('注册失败', 500);
    }

    public static function payload(array $user, string $csrf): array
    {
        return [
            'id' => (string) $user['id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
            'hasApiKey' => ($user['api_key'] ?? '') !== '',
            'apiKeyMask' => $user['api_key_mask'] ?: null,
            'csrf' => $csrf,
        ];
    }

    public static function checkLoginAllowed(string $username, string $ip): void
    {
        $stmt = Database::pdo()->prepare('SELECT attempts, window_start_ms FROM login_attempts WHERE username = ? AND ip = ?');
        $stmt->execute([$username, $ip]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }
        $now = (int) floor(microtime(true) * 1000);
        if ((int) $row['window_start_ms'] + 600000 > $now && (int) $row['attempts'] >= 5) {
            throw new HttpError('登录尝试过于频繁，请 10 分钟后再试', 429);
        }
    }

    public static function recordLoginFailure(string $username, string $ip): void
    {
        $now = (int) floor(microtime(true) * 1000);
        $stmt = Database::pdo()->prepare('SELECT attempts, window_start_ms FROM login_attempts WHERE username = ? AND ip = ?');
        $stmt->execute([$username, $ip]);
        $row = $stmt->fetch();
        if (!$row) {
            $stmt = Database::pdo()->prepare('INSERT INTO login_attempts (username, ip, attempts, window_start_ms) VALUES (?, ?, 1, ?)');
            $stmt->execute([$username, $ip, $now]);
            return;
        }
        if ((int) $row['window_start_ms'] + 600000 <= $now) {
            $stmt = Database::pdo()->prepare('UPDATE login_attempts SET attempts = 1, window_start_ms = ? WHERE username = ? AND ip = ?');
            $stmt->execute([$now, $username, $ip]);
            return;
        }
        $stmt = Database::pdo()->prepare('UPDATE login_attempts SET attempts = attempts + 1 WHERE username = ? AND ip = ?');
        $stmt->execute([$username, $ip]);
    }

    public static function clearLoginFailures(string $username, string $ip): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM login_attempts WHERE username = ? AND ip = ?');
        $stmt->execute([$username, $ip]);
    }

    public static function updatePassword(string $userId, string $password): void
    {
        if (strlen($password) < 8) {
            throw new HttpError('新密码至少 8 位', 422);
        }
        $stmt = Database::pdo()->prepare('UPDATE users SET password_hash = ?, updated_ms = ? WHERE id = ?');
        $stmt->execute([Auth::passwordHash($password), (int) floor(microtime(true) * 1000), $userId]);
    }

    /** 修改用户名：校验格式与唯一性，返回新用户名 */
    public static function rename(string $userId, string $username): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            throw new HttpError('用户名需为 3-32 位字母、数字或下划线', 422);
        }
        $existing = self::findByUsername($username);
        if ($existing !== null && (string) $existing['id'] !== $userId) {
            throw new HttpError('用户名已存在', 409);
        }
        $stmt = Database::pdo()->prepare('UPDATE users SET username = ?, updated_ms = ? WHERE id = ?');
        $stmt->execute([$username, (int) floor(microtime(true) * 1000), $userId]);
        return $username;
    }

    public static function saveApiKey(string $userId, string $key): string
    {
        $mask = Auth::maskKey($key);
        $stmt = Database::pdo()->prepare('UPDATE users SET api_key = ?, api_key_mask = ?, updated_ms = ? WHERE id = ?');
        $stmt->execute([$key, $mask, (int) floor(microtime(true) * 1000), $userId]);
        return (string) $mask;
    }

    public static function deleteApiKey(string $userId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET api_key = NULL, api_key_mask = NULL, updated_ms = ? WHERE id = ?');
        $stmt->execute([(int) floor(microtime(true) * 1000), $userId]);
    }

    public static function logAdminAction(string $adminId, string $action, string $targetType, string $targetId, string $detail = ''): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO admin_logs (id, admin_id, action, target_type, target_id, detail, ip, created_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Snowflake::instance()->nextId(),
            $adminId,
            $action,
            $targetType,
            $targetId,
            $detail,
            Request::ip(),
            (int) floor(microtime(true) * 1000),
        ]);
    }
}
