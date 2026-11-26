<?php
declare(strict_types=1);

/**
 * 兼容入口：部分主机没有开启 URL 重写时，/install/ 由目录索引直接命中本文件。
 * 安装逻辑位于 app/Install/install.php。
 */
require dirname(__DIR__) . '/app/Install/install.php';
