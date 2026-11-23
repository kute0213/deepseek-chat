<?php
declare(strict_types=1);

namespace App\Support;

final class Log
{
    public static function write(string $channel, string $message): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $channel, $message);
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
