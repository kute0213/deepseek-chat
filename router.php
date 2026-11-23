<?php
declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

if (is_file(__DIR__ . $path)) {
    return false;
}

if (str_starts_with($path, '/admin/') || $path === '/admin') {
    require __DIR__ . '/admin/index.php';
    return true;
}

require __DIR__ . '/index.php';
