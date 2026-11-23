<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DeepSeekClient;
use App\Support\Auth;
use App\Support\HttpError;
use App\Support\Request;

final class ChatController
{
    public function messages(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $key = (string) ($user['api_key'] ?? '');
        if ($key === '') {
            throw new HttpError('请先在设置中配置 API Key', 422);
        }
        $body = $request->body();
        if ($body === '' || !is_array(json_decode($body, true))) {
            throw new HttpError('请求体格式错误', 400);
        }
        DeepSeekClient::streamMessages($key, $body);
    }

    public function uploadFile(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $key = (string) ($user['api_key'] ?? '');
        if ($key === '') {
            throw new HttpError('请先在设置中配置 API Key', 422);
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            throw new HttpError('未选择文件', 400);
        }
        $purpose = isset($_POST['purpose']) && is_string($_POST['purpose']) ? $_POST['purpose'] : null;
        [$status, $body] = DeepSeekClient::uploadFile($key, $file, $purpose);
        http_response_code(DeepSeekClient::proxyStatus($status));
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        exit;
    }

    public function deleteFile(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $key = (string) ($user['api_key'] ?? '');
        if ($key === '') {
            throw new HttpError('请先在设置中配置 API Key', 422);
        }
        [$status, $body] = DeepSeekClient::deleteFile($key, (string) $params['fileId']);
        http_response_code(DeepSeekClient::proxyStatus($status));
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        exit;
    }
}
