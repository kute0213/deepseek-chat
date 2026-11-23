<?php
declare(strict_types=1);

namespace App\Support;

final class Config
{
    private static ?array $data = null;

    public static function load(string $path): void
    {
        if (is_file($path)) {
            $data = require $path;
            self::$data = is_array($data) ? $data : [];
        }
    }

    /** @return mixed */
    public static function get(string $key, $default = null)
    {
        $value = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function installed(): bool
    {
        return is_file(APP_ROOT . '/config/installed.lock');
    }
}
