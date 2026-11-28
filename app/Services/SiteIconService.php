<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Response;

/**
 * AI 联网搜索结果的站点图标服务端缓存。
 *
 * 搜索接口返回的 site_icon 可能是相对路径（例如 /api/files/{uuid}），
 * 浏览器直接加载会请求到本站并 404；这里统一解析、下载并缓存到 uploads，
 * 再以长缓存返回，前端只需把原地址交给 /api/site-icon。
 */
final class SiteIconService
{
    private const MAX_BYTES = 524288;
    private const CACHE_TTL = 2592000;

    /** @var array<string, string> */
    private const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/avif' => 'avif',
    ];

    public static function serve(string $raw, string $page = ''): void
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 2048) {
            self::fallback();
        }
        $candidates = self::candidates($raw, $page);
        foreach ($candidates as $url) {
            $cached = self::cached($url);
            if ($cached !== null) {
                self::send($cached);
            }
        }
        foreach ($candidates as $url) {
            $saved = self::download($url);
            if ($saved !== null) {
                self::send($saved);
            }
        }
        self::fallback();
    }

    /** @return string[] */
    private static function candidates(string $raw, string $page): array
    {
        if (str_starts_with($raw, '//')) {
            $raw = 'https:' . $raw;
        }
        if (str_starts_with($raw, '/')) {
            $list = [];
            // 相对图标可能来自被搜索网站自身（如 /favicon.ico）
            $parts = parse_url($page);
            if (is_array($parts) && !empty($parts['host'])) {
                $origin = strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
                if (self::allowed($origin . '/')) {
                    $list[] = $origin . $raw;
                }
            }
            // 也可能来自 DeepSeek 的文件 / CDN 域名
            $base = rtrim(SiteSettings::get('api_base_url', 'https://api.deepseek.com'), '/');
            $list[] = $base . $raw;
            foreach (['https://chat.deepseek.com', 'https://cdn.deepseek.com'] as $alt) {
                if (!str_starts_with($base, $alt)) {
                    $list[] = $alt . $raw;
                }
            }
            return array_values(array_unique($list));
        }
        return self::allowed($raw) ? [$raw] : [];
    }

    private static function allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (!in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private static function directory(): string
    {
        $dir = APP_ROOT . '/uploads/cache/site-icons';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        return $dir;
    }

    private static function cached(string $url): ?string
    {
        $dir = self::directory();
        if ($dir === '') {
            return null;
        }
        $matches = glob($dir . '/' . hash('sha256', $url) . '.*') ?: [];
        foreach ($matches as $file) {
            if (is_file($file) && filemtime($file) > time() - self::CACHE_TTL) {
                return $file;
            }
        }
        return null;
    }

    private static function download(string $url): ?string
    {
        $dir = self::directory();
        if ($dir === '') {
            return null;
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DSW-SiteIcon/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/x-icon,image/*;q=0.8,*/*;q=0.5'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $mime = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $failed = $ok === false || $status < 200 || $status >= 300 || $body === '';
        // 重定向后的地址必须仍是公网地址，避免被诱导访问内网
        if (!$failed && $effective !== $url && !self::allowed($effective)) {
            $failed = true;
        }
        curl_close($ch);
        if ($failed || strlen($body) > self::MAX_BYTES) {
            return null;
        }
        $ext = self::TYPES[$mime] ?? '';
        if ($ext === '') {
            return null;
        }
        $path = $dir . '/' . hash('sha256', $url) . '.' . $ext;
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $body) === false) {
            return null;
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return null;
        }
        return $path;
    }

    private static function send(string $path): never
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = array_search($ext, self::TYPES, true) ?: 'image/png';
        header('Cache-Control: public, max-age=' . self::CACHE_TTL);
        Response::file($path, (string) $mime, 'site-icon.' . $ext);
    }

    /** 没有可用图标时返回占位图，避免浏览器控制台出现 404 */
    private static function fallback(): never
    {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=3600');
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">'
            . '<path fill="none" stroke="#9aa3b5" stroke-width="1.4" stroke-linecap="round" '
            . 'd="M6.4 9.6l3.2-3.2M5.5 7.2 4.2 8.5a2.3 2.3 0 0 0 3.3 3.3l1.3-1.3'
            . 'M10.5 8.8l1.3-1.3a2.3 2.3 0 0 0-3.3-3.3L7.2 5.5"/></svg>';
        exit;
    }
}
