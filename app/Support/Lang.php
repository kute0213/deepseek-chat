<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 极简语言文件读取器：键值来自 app/lang/{locale}.php。
 * 服务端页面通过 Lang::siteName() / Lang::get() 获取统一文案。
 */
final class Lang
{
    /** @var array<string, string>|null */
    private static ?array $strings = null;

    public static function get(string $key, string $fallback = ''): string
    {
        if (self::$strings === null) {
            $file = dirname(__DIR__) . '/lang/zh-CN.php';
            $data = is_file($file) ? require $file : [];
            self::$strings = [];
            foreach (is_array($data) ? $data : [] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    self::$strings[$k] = $v;
                }
            }
        }
        return self::$strings[$key] ?? $fallback;
    }

    public static function siteName(): string
    {
        return self::get('site.name', 'DSW');
    }

    public static function siteFullName(): string
    {
        return self::get('site.full_name', 'DeepSeekWebAPIChat');
    }
}
