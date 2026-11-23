<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;

/**
 * 图形验证码：生成、验证、销毁共用同一套实现。
 * 答案只保存 SHA-256，验证时一次性消费，避免重复使用与暴力尝试。
 */
final class Captcha
{
    private const TTL_MS = 300000;
    private const CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private static bool $ready = false;

    private static function ensureTable(): void
    {
        if (self::$ready) {
            return;
        }
        Database::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS captchas (
                id CHAR(32) NOT NULL,
                answer_hash CHAR(64) NOT NULL,
                ip VARCHAR(45) NOT NULL DEFAULT \'\',
                created_ms BIGINT NOT NULL,
                expires_ms BIGINT NOT NULL,
                PRIMARY KEY (id),
                KEY idx_captchas_expires (expires_ms)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$ready = true;
    }

    /** @return array{id: string, image: string} */
    public static function generate(string $ip = ''): array
    {
        self::ensureTable();
        $now = (int) floor(microtime(true) * 1000);
        $stmt = Database::pdo()->prepare('DELETE FROM captchas WHERE expires_ms < ?');
        $stmt->execute([$now]);

        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }
        $id = bin2hex(random_bytes(16));
        $stmt = Database::pdo()->prepare(
            'INSERT INTO captchas (id, answer_hash, ip, created_ms, expires_ms) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, hash('sha256', $code), substr($ip, 0, 45), $now, $now + self::TTL_MS]);

        return [
            'id' => $id,
            'image' => 'data:image/svg+xml;base64,' . base64_encode(self::svg($code)),
        ];
    }

    /** 校验并消费验证码；错误时抛出可直接展示的 HttpError */
    public static function verify(?string $id, ?string $code): void
    {
        self::ensureTable();
        $id = strtolower(trim((string) $id));
        $code = strtoupper(trim((string) $code));
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || $code === '') {
            throw new HttpError('请输入图形验证码', 422);
        }

        $stmt = Database::pdo()->prepare('SELECT answer_hash, expires_ms FROM captchas WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        // 无论对错都立即销毁，防止同一验证码被反复尝试
        $stmt = Database::pdo()->prepare('DELETE FROM captchas WHERE id = ?');
        $stmt->execute([$id]);

        if (!$row) {
            throw new HttpError('验证码已失效，请刷新后重试', 422);
        }
        if ((int) $row['expires_ms'] < (int) floor(microtime(true) * 1000)) {
            throw new HttpError('验证码已过期，请刷新后重试', 422);
        }
        if (!hash_equals((string) $row['answer_hash'], hash('sha256', $code))) {
            throw new HttpError('验证码错误', 422);
        }
    }

    /** 主动销毁（如切换登录方式时放弃当前验证码） */
    public static function destroy(string $id): void
    {
        self::ensureTable();
        $stmt = Database::pdo()->prepare('DELETE FROM captchas WHERE id = ?');
        $stmt->execute([$id]);
    }

    private static function svg(string $code): string
    {
        $width = 132;
        $height = 44;
        $parts = ['<rect width="100%" height="100%" fill="#f3f4f6"/>'];

        for ($i = 0; $i < 5; $i++) {
            $parts[] = sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1" opacity="0.45"/>',
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                sprintf('#%02x%02x%02x', random_int(120, 190), random_int(140, 200), random_int(190, 230))
            );
        }
        for ($i = 0; $i < 26; $i++) {
            $parts[] = sprintf(
                '<circle cx="%d" cy="%d" r="1" fill="#%02x%02x%02x" opacity="0.5"/>',
                random_int(4, $width - 4),
                random_int(4, $height - 4),
                random_int(90, 150),
                random_int(110, 170),
                random_int(150, 210)
            );
        }
        foreach (str_split($code) as $i => $char) {
            $parts[] = sprintf(
                '<text x="0" y="0" transform="translate(%d %d) rotate(%d)" '
                . 'font-family="ui-monospace, Menlo, Consolas, monospace" font-size="26" font-weight="700" '
                . 'fill="#%02x%02x%02x" text-anchor="middle">%s</text>',
                22 + $i * 29,
                31 + random_int(-3, 3),
                random_int(-16, 16),
                random_int(30, 70),
                random_int(60, 110),
                random_int(130, 200),
                htmlspecialchars($char, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            );
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" '
            . 'viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="图形验证码">'
            . implode('', $parts) . '</svg>';
    }
}
