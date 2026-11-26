<?php
declare(strict_types=1);

/**
 * 兼容入口：部分主机没有开启 URL 重写时，/docs/ 由目录索引直接命中本文件。
 * 真正的文档路由与页面渲染仍由根入口 index.php 与前端完成。
 */
require dirname(__DIR__) . '/app/Bootstrap.php';

use App\Bootstrap;
use App\Services\HomePage;

Bootstrap::boot();
HomePage::render();
