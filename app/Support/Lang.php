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

    private static string $locale = 'zh-CN';

    /** 切换服务端语言；非法值回退中文。 */
    public static function setLocale(string $locale): void
    {
        $normalized = $locale === 'en-US' ? 'en-US' : 'zh-CN';
        if ($normalized !== self::$locale) {
            self::$locale = $normalized;
            self::$strings = null;
        }
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @return array<string, string> */
    private static function load(string $locale): array
    {
        $file = dirname(__DIR__) . '/lang/' . $locale . '.php';
        $data = is_file($file) ? require $file : [];
        $strings = [];
        foreach (is_array($data) ? $data : [] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $strings[$k] = $v;
            }
        }
        return $strings;
    }

    private static function strings(): array
    {
        if (self::$strings === null) {
            self::$strings = self::load(self::$locale);
            if (self::$locale !== 'zh-CN') {
                // 英文缺省键回退中文，保证页面不出现空文案
                foreach (self::load('zh-CN') as $k => $v) {
                    if (!array_key_exists($k, self::$strings)) {
                        self::$strings[$k] = $v;
                    }
                }
            }
        }
        return self::$strings;
    }

    public static function get(string $key, string $fallback = ''): string
    {
        return self::strings()[$key] ?? $fallback;
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
