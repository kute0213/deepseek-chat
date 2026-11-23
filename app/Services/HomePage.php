<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 用 PHP 输出前端构建产物：注入站点名称与公开配置，
 * 让首页标题、站点信息在首屏即为服务端渲染结果。
 */
final class HomePage
{
    /** 输出根目录下的公开静态页面（服务协议、404 等） */
    public static function renderView(string $file, int $status = 200): void
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException('页面不存在：' . $file);
        }
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        readfile($path);
    }

    public static function render(): void
    {
        $file = dirname(__DIR__, 2) . '/index.html';
        if (!is_file($file)) {
            throw new \RuntimeException('前端资源未构建，请先在 frontend 目录执行 pnpm build');
        }
        $html = (string) file_get_contents($file);
        $site = SiteSettings::publicInfo();
        $name = htmlspecialchars((string) $site['siteName'], ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/<title>.*?<\/title>/i', '<title>' . $name . '</title>', $html, 1) ?? $html;
        $json = json_encode($site, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $html = str_replace('</head>', '<script>window.__SITE__=' . ($json ?: 'null') . ';</script></head>', $html);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }
}
