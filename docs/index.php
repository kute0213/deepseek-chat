<?php
declare(strict_types=1);

/**
 * /docs 目录入口：把文档路由交给首页渲染，避免服务器把真实目录
 * 当成目录列表（403）。Apache、Nginx 与 PHP 内置服务器都能直接命中这里。
 */
require dirname(__DIR__) . '/app/Bootstrap.php';

use App\Bootstrap;
use App\Services\HomePage;
use App\Support\Config;

Bootstrap::boot();

if (!Config::installed()) {
    header('Location: /install');
    exit;
}

HomePage::render();
