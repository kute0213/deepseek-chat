<?php
declare(strict_types=1);

/**
 * 安装向导入口：/install/ 由服务器目录索引执行本文件，
 * 再交给根 index.php 统一分发；无需伪装静态页或额外重写规则。
 */
require dirname(__DIR__) . '/index.php';
