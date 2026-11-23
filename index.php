<?php
declare(strict_types=1);

require __DIR__ . '/app/Bootstrap.php';

use App\Bootstrap;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\ChatController;
use App\Controllers\FileController;
use App\Controllers\HistoryController;
use App\Controllers\IdController;
use App\Controllers\SessionController;
use App\Controllers\SiteController;
use App\Controllers\SpeechController;
use App\Router;
use App\Support\Config;

Bootstrap::boot();

if (!Config::installed()) {
    header('Location: /install.php');
    exit;
}

$path = App\Support\Request::path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 服务器把 index.php 当作默认首页时，非 API 请求直接返回前端页面
if ($method === 'GET' && !str_starts_with($path, '/api/')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

$router = new Router();

$router->get('/api/site', [SiteController::class, 'show']);

$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);

$router->post('/api/account/password', [AccountController::class, 'changePassword']);
$router->post('/api/account/api-key', [AccountController::class, 'saveApiKey']);
$router->delete('/api/account/api-key', [AccountController::class, 'deleteApiKey']);
$router->get('/api/account/balance', [AccountController::class, 'balance']);

$router->post('/api/ids', [IdController::class, 'create']);

$router->get('/api/sessions', [SessionController::class, 'index']);
$router->get('/api/sessions/{id}', [SessionController::class, 'show']);
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
