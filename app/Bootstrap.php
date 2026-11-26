<?php
declare(strict_types=1);

namespace App;

use App\Support\Config;
use App\Support\HttpError;
use App\Support\Log;
use App\Support\Response;

final class Bootstrap
{
    public static function boot(): void
    {
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__));
        }

        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }
            $path = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });

        Config::load(APP_ROOT . '/uploads/config/config.php');
        date_default_timezone_set('Asia/Shanghai');
        header_remove('X-Powered-By');

        set_exception_handler(static function (\Throwable $e): void {
            if ($e instanceof HttpError) {
                Response::error($e->getMessage(), $e->status, $e->extra);
                return;
            }
            Log::write('error', $e->getMessage() . "\n"
                . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . "\n"
                . $e->getTraceAsString());
            if (str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
                Response::error('服务器内部错误', 500);
                return;
            }
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo '服务器内部错误';
        });
    }
}
