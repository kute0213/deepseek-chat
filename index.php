<?php
declare(strict_types=1);

require __DIR__ . '/app/Bootstrap.php';

use App\Bootstrap;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CaptchaController;
use App\Controllers\ChatController;
use App\Controllers\DocsController;
use App\Controllers\FileController;
use App\Controllers\HistoryController;
use App\Controllers\IdController;
use App\Controllers\SessionController;
use App\Controllers\ShareController;
use App\Controllers\SiteController;
use App\Controllers\SpeechController;
use App\Services\HomePage;
use App\Router;
use App\Support\Config;

Bootstrap::boot();

$path = App\Support\Request::path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 安装向导：文件位于 app/Install，这里统一分发，未安装时其它路径都会被引导过来
if ($path === '/install' || $path === '/install.php') {
    require __DIR__ . '/app/Install/install.php';
    exit;
}

if (!Config::installed()) {
    header('Location: /install');
    exit;
}

// 旧版服务协议页面已由 docs 路由统一承载，访问旧地址时直接跳转
if ($method === 'GET' && ($path === '/terms' || $path === '/terms.html')) {
    header('Location: /docs?doc=' . rawurlencode('用户协议.md'), true, 302);
    exit;
}
if ($method === 'GET' && $path === '/404') {
    HomePage::renderView('app/Views/404.html', 404);
    exit;
}

// 管理后台统一走 app/Admin 模块，避免根目录出现额外入口文件
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require __DIR__ . '/app/Admin/index.php';
    exit;
}

// 非 API 的 GET 请求由 PHP 输出前端页面，注入站点名称与公开配置
if ($method === 'GET' && !str_starts_with($path, '/api/')) {
    HomePage::render();
    exit;
}

$router = new Router();

$router->get('/api/site', [SiteController::class, 'show']);

$router->get('/api/auth/captcha', [CaptchaController::class, 'show']);
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);

$router->post('/api/account/password', [AccountController::class, 'changePassword']);
$router->post('/api/account/username', [AccountController::class, 'renameUsername']);
$router->post('/api/account/api-key', [AccountController::class, 'saveApiKey']);
$router->delete('/api/account/api-key', [AccountController::class, 'deleteApiKey']);
$router->get('/api/account/balance', [AccountController::class, 'balance']);
$router->get('/api/account/usage', [AccountController::class, 'usage']);
$router->post('/api/account/delete', [AccountController::class, 'destroy']);

$router->get('/api/docs', [DocsController::class, 'index']);

$router->post('/api/ids', [IdController::class, 'create']);

$router->get('/api/sessions', [SessionController::class, 'index']);
$router->get('/api/sessions/search', [SessionController::class, 'search']);
$router->get('/api/sessions/{id}', [SessionController::class, 'show']);

$router->get('/api/shares', [ShareController::class, 'index']);
$router->post('/api/shares', [ShareController::class, 'create']);
$router->delete('/api/shares/{token}', [ShareController::class, 'revoke']);
$router->get('/api/share/{token}', [ShareController::class, 'show']);
$router->post('/api/share/{token}/fork', [ShareController::class, 'fork']);
$router->put('/api/sessions/{id}', [SessionController::class, 'save']);
$router->patch('/api/sessions/{id}', [SessionController::class, 'rename']);
$router->delete('/api/sessions/{id}', [SessionController::class, 'delete']);

$router->put('/api/files/{id}', [FileController::class, 'put']);
$router->get('/api/files/{id}', [FileController::class, 'get']);

$router->delete('/api/history/staging', [HistoryController::class, 'clear']);
$router->post('/api/history/staging/sessions', [HistoryController::class, 'addSession']);
$router->post('/api/history/staging/files/{id}', [HistoryController::class, 'addFile']);
$router->put('/api/history/staging/file-types', [HistoryController::class, 'updateFileTypes']);
$router->post('/api/history/staging/commit', [HistoryController::class, 'commit']);

$router->post('/api/deepseek/messages', [ChatController::class, 'messages']);
$router->post('/api/deepseek/files', [ChatController::class, 'uploadFile']);
$router->delete('/api/deepseek/files/{fileId}', [ChatController::class, 'deleteFile']);
$router->post('/api/deepseek/tools/asr', [SpeechController::class, 'asr']);
$router->post('/api/deepseek/tools/tts', [SpeechController::class, 'tts']);

$router->dispatch($method, $path);
