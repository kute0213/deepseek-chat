<?php
declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

$html = static function (string $file, int $status = 200): bool {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . $file);
    return true;
};

if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

if (is_file(__DIR__ . $path)) {
    return false;
}

if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require __DIR__ . '/admin/index.php';
    return true;
}

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}

// 缺失的静态资源返回 404 页面，其余路径交给 PHP 输出前端页面
if (preg_match('#\.[A-Za-z0-9]{1,8}$#', $path)) {
    return $html('/404.html', 404);
}

require __DIR__ . '/index.php';
return true;
