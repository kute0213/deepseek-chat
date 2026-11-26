<?php
declare(strict_types=1);

/**
 * 兼容入口：部分主机没有开启 URL 重写时，/admin/ 由目录索引直接命中本文件。
 * 管理后台的实际代码位于 app/Admin，本文件只负责转发。
 */
require dirname(__DIR__) . '/app/Admin/index.php';
