<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\HttpError;

final class DeepSeekClient
{
    private const FILES_BETA = 'files-api-2025-04-14';

    private static function base(): string
    {
        return rtrim(SiteSettings::get('api_base_url', 'https://api.deepseek.com'), '/');
    }

    private static function headers(string $key, bool $filesBeta = false): array
    {
        return array_merge([
            'x-api-key: ' . $key,
            'Authorization: Bearer ' . $key,
        ], $filesBeta ? ['anthropic-beta: ' . self::FILES_BETA] : []);
    }

    /** @return array{0:int,1:string} */
    private static function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new HttpError('连接 DeepSeek 失败：' . $error, 502);
        }
        return [$status, (string) $response];
    }

    public static function verifyKey(string $key): bool
    {
        [$status] = self::request('GET', self::base() . '/models', self::headers($key));
        return $status === 200;
    }

    public static function balance(string $key): array
    {
        [$status, $body] = self::request('GET', self::base() . '/user/balance', self::headers($key));
        if ($status < 200 || $status >= 300) {
            throw new HttpError(self::errorMessage($body, '获取余额失败'), $status >= 400 ? $status : 502);
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /** @return array{0:int,1:string} */
    public static function uploadFile(string $key, array $file, ?string $purpose): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new HttpError('未选择文件', 400);
        }
        $mime = (string) ($file['type'] ?? '');
        if ($mime === '') {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png', 'apng' => 'image/png',
                'jpg', 'jpeg', 'jpe', 'jfif', 'pjpeg', 'pjp' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'bmp', 'dib' => 'image/bmp',
                'svg', 'svgz' => 'image/svg+xml',
                'ico', 'cur' => 'image/x-icon',
                'tif', 'tiff' => 'image/tiff',
                'avif', 'avifs' => 'image/avif',
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'txt', 'md', 'markdown', 'csv', 'tsv', 'log', 'ini', 'conf', 'yaml', 'yml',
                'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'css', 'scss', 'less', 'html', 'htm',
                'xml', 'json', 'py', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'go', 'rs', 'rb',
                'php', 'sh', 'bash', 'zsh', 'sql' => 'text/plain',
                default => 'application/octet-stream',
            };
        }
        $fields = [];
        if ($purpose !== null && $purpose !== '') {
            $fields['purpose'] = $purpose;
        }
        $fields['file'] = new \CURLFile($tmp, $mime, (string) ($file['name'] ?? 'file'));

        $ch = curl_init(self::base() . '/anthropic/v1/files');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => self::headers($key, true),
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new HttpError('上传附件失败：' . $error, 502);
        }
        return [$status, (string) $response];
    }

    /** @return array{0:int,1:string} */
    public static function deleteFile(string $key, string $fileId): array
    {
        return self::request(
            'DELETE',
            self::base() . '/anthropic/v1/files/' . rawurlencode($fileId),
            self::headers($key, true)
        );
    }

    /** @return array{0:int,1:string} */
    public static function asr(string $key, array $file): array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new HttpError('未收到录音', 400);
        }
        $ch = curl_init(self::base() . '/v1/tools/asr');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => self::headers($key),
            CURLOPT_POSTFIELDS => ['file' => new \CURLFile($tmp, (string) ($file['type'] ?? 'audio/wav'), 'recording.wav')],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new HttpError('语音识别失败：' . $error, 502);
        }
        return [$status, (string) $response];
    }

    public static function streamMessages(string $key, string $body): never
    {
        $hasFile = false;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['messages']) && is_array($decoded['messages'])) {
            foreach ($decoded['messages'] as $message) {
                if (!is_array($message) || !isset($message['content']) || !is_array($message['content'])) {
                    continue;
                }
                foreach ($message['content'] as $block) {
                    if (is_array($block) && in_array($block['type'] ?? '', ['image', 'document'], true)) {
                        $hasFile = true;
                        break 2;
                    }
                }
            }
        }

        $headers = self::headers($key, $hasFile);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: text/event-stream';

        self::streamRequest(
            self::base() . '/anthropic/v1/messages',
            $headers,
            $body,
            'text/event-stream; charset=utf-8',
            900
        );
    }

    public static function streamTts(string $key, string $body): never
    {
        $headers = self::headers($key);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/octet-stream';

        self::streamRequest(
            self::base() . '/v1/tools/tts',
            $headers,
            $body,
            'application/octet-stream',
            300
        );
    }

    private static function streamRequest(string $url, array $headers, string $body, string $contentType, int $timeout): never
    {
        set_time_limit(0);
        ignore_user_abort(false);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $status = 0;
        $errorBody = '';
        $sentHeaders = false;
        $output = 0;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                    $status = (int) $m[1];
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$status, &$errorBody, &$sentHeaders, &$output, $contentType): int {
                if (connection_aborted()) {
                    return 0;
                }
                if ($status >= 400) {
                    if (strlen($errorBody) < 1048576) {
                        $errorBody .= $chunk;
                    }
                    return strlen($chunk);
                }
                if (!$sentHeaders) {
                    header('Content-Type: ' . $contentType);
                    header('Cache-Control: no-cache');
                    header('X-Accel-Buffering: no');
                    $sentHeaders = true;
                }
                echo $chunk;
                flush();
                $output += strlen($chunk);
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($status >= 400) {
            http_response_code(self::proxyStatus($status));
            header('Content-Type: application/json; charset=utf-8');
            echo $errorBody !== '' ? $errorBody : json_encode(['error' => ['message' => '上游请求失败']], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ok === false && $output === 0) {
            http_response_code(502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => ['message' => '上游连接失败：' . $error]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        exit;
    }

    /** 上游 401 表示用户的 API Key 失效，不能透传给前端，否则会被当成登录态过期 */
    public static function proxyStatus(int $status): int
    {
        return $status === 401 ? 424 : $status;
    }

    private static function errorMessage(string $body, string $fallback): string
    {
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['error']['message']) && is_string($data['error']['message'])) {
            return $data['error']['message'];
        }
        return $fallback;
    }
}
