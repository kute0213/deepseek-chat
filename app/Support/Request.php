<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function path(): string
    {
        // 未配置伪静态时，前端会通过 index.php?_r=/api/... 访问
        $route = $_GET['_r'] ?? null;
        $path = is_string($route) && str_starts_with($route, '/')
            ? $route
            : (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    public static function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? $default;
    }

    public static function cookie(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function body(): string
    {
        static $body = null;
        if ($body === null) {
            $body = (string) file_get_contents('php://input');
        }
        return $body;
    }

    public static function json(): array
    {
        $body = self::body();
        if ($body === '') {
            return [];
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError('请求格式错误', 400);
        }
        return is_array($data) ? $data : [];
    }

    public static function query(string $name, ?string $default = null): ?string
    {
        $value = $_GET[$name] ?? null;
        return is_string($value) ? $value : $default;
    }
}
