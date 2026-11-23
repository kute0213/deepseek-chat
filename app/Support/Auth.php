<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

final class Auth
{
    public const COOKIE = 'ds_token';
    private const TTL_MS = 2592000000; // 30 天
    private static ?array $user = null;
    private static ?string $tokenHash = null;

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $raw = Request::cookie(self::COOKIE);
        if ($raw === null || $raw === '') {
            return null;
        }
        $hash = hash('sha256', $raw);
        $stmt = Database::pdo()->prepare(
            'SELECT t.id AS token_id, t.token_hash, t.csrf_token, u.id, u.username, u.role, u.status, u.api_key, u.api_key_mask
             FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_ms > ?'
        );
        $stmt->execute([$hash, (int) floor(microtime(true) * 1000)]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['status'] !== 1) {
            return null;
        }
        self::$user = $row;
        self::$tokenHash = $hash;
        return $row;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw new HttpError('请先登录', 401);
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if ($user['role'] !== 'admin') {
            throw new HttpError('无权访问', 403);
        }
        return $user;
    }

    public static function login(string $userId): array
    {
        $raw = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $now = (int) floor(microtime(true) * 1000);
        $id = Snowflake::instance()->nextId();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO auth_tokens (id, user_id, token_hash, csrf_token, created_ms, expires_ms, last_used_ms, ip, ua)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            hash('sha256', $raw),
            $csrf,
            $now,
            $now + self::TTL_MS,
            $now,
            Request::ip(),
            Request::userAgent(),
        ]);

        setcookie(self::COOKIE, $raw, [
            'expires' => (int) floor(($now + self::TTL_MS) / 1000),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);

        self::$user = null;
        self::$tokenHash = null;
        return ['csrf' => $csrf];
    }

    public static function logout(): void
    {
        $hash = self::$tokenHash;
        if ($hash !== null) {
            $stmt = Database::pdo()->prepare('DELETE FROM auth_tokens WHERE token_hash = ?');
            $stmt->execute([$hash]);
        }
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        self::$user = null;
        self::$tokenHash = null;
    }

    public static function revokeOtherTokens(string $userId): void
    {
        $sql = 'DELETE FROM auth_tokens WHERE user_id = ?';
        $params = [$userId];
        if (self::$tokenHash !== null) {
            $sql .= ' AND token_hash <> ?';
            $params[] = self::$tokenHash;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
    }

    public static function requireCsrf(): void
    {
        $user = self::user();
        if ($user === null) {
            return;
        }
        $provided = Request::header('X-CSRF-Token', '');
        if (!is_string($provided) || !hash_equals((string) $user['csrf_token'], $provided)) {
            throw new HttpError('CSRF 校验失败，请刷新页面', 403);
        }
    }

    public static function checkOrigin(): void
    {
        $origin = Request::header('Origin');
        if ($origin === null || $origin === '') {
            return;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $host = explode(':', $host)[0];
        if ($originHost === null || !hash_equals($host, $originHost)) {
            throw new HttpError('非法来源', 403);
        }
    }

    public static function passwordHash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function maskKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $tail = substr($key, -4);
        return 'sk-****' . $tail;
    }
}
