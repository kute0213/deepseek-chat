<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DeepSeekClient;
use App\Support\Auth;
use App\Support\HttpError;
use App\Support\Request;

final class SpeechController
{
    public function asr(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $key = (string) ($user['api_key'] ?? '');
        if ($key === '') {
            throw new HttpError('请先在设置中配置 API Key', 422);
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            throw new HttpError('未收到录音', 400);
        }
        [$status, $body] = DeepSeekClient::asr($key, $file);
        http_response_code(DeepSeekClient::proxyStatus($status));
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        exit;
    }

    public function tts(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $key = (string) ($user['api_key'] ?? '');
        if ($key === '') {
            throw new HttpError('请先在配置中填写 API Key', 422);
        }
        $body = $request->body();
        if ($body === '') {
            throw new HttpError('请求体为空', 400);
        }
        DeepSeekClient::streamTts($key, $body);
    }
}
