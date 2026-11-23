<?php
declare(strict_types=1);

/**
 * /install 的物理入口：兼容未开启伪静态的服务器。
 * 开启伪静态时由根 .htaccess 直接重写到 index.php，不会走到这里。
 */
require dirname(__DIR__) . '/index.php';
