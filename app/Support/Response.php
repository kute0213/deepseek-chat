<?php
declare(strict_types=1);

namespace App\Support;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['error' => array_merge(['message' => $message], $extra)], $status);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    public static function file(string $path, string $mime, string $name, bool $inline = true): never
    {
        if (!is_file($path)) {
            throw new HttpError('文件不存在', 404);
        }
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        $disposition = $inline ? 'inline' : 'attachment';
        header(sprintf('Content-Disposition: %s; filename="%s"', $disposition, rawurlencode($name)));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
