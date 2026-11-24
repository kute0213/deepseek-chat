<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Lang;

final class SiteSettings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Database::pdo()->query('SELECT k, v FROM site_settings')->fetchAll();
            self::$cache = [];
            foreach ($rows as $row) {
                self::$cache[$row['k']] = (string) $row['v'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO site_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        $stmt->execute([$key, $value]);
        self::$cache = null;
    }

    public static function publicInfo(): array
    {
        $siteName = self::get('site_name', Lang::siteName());
        // 旧安装的默认站点名统一迁移到正式名称
        if (in_array($siteName, ['DeepSeek', 'DeepSeekChat'], true)) {
            $siteName = Lang::siteName();
            self::set('site_name', $siteName);
        }
        return [
            'siteName' => $siteName,
            'allowRegister' => self::get('allow_register', '1') === '1',
            'announcement' => self::get('announcement', ''),
            'maxUploadMb' => (int) self::get('max_upload_mb', '64'),
        ];
    }
}
