<?php
declare(strict_types=1);

/**
 * PHP 内置服务器路由（开发用）：
 * php -S 127.0.0.1:8080 app/Dev/router.php
 * 静态文件直接返回，管理后台交给 app/Admin/index.php，其余统一由根入口处理。
 */
$root = dirname(__DIR__, 2);
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/') {
    require $root . '/index.php';
    return true;
}

if (str_starts_with($path, '/uploads')) {
    http_response_code(403);
    return true;
}

if (is_file($root . $path)) {
    return false;
}

// 安装向导与 404 页面由根入口分发
$views = ['/install', '/install.php', '/404', '/404.html'];
if (in_array($path, $views, true)) {
    require $root . '/index.php';
    return true;
}

// 带扩展名但文件不存在：返回 404 页面，避免把缺失静态资源当成 SPA 首页
if (preg_match('/\.[A-Za-z0-9]{1,8}$/', $path)) {
    $_GET['_r'] = '/404';
    require $root . '/index.php';
    return true;
}

if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require $root . '/app/Admin/index.php';
    return true;
}

require $root . '/index.php';
return true;
