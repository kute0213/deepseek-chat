<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\FileStore;
use App\Support\Auth;
use App\Support\HttpError;
use App\Support\Request;
use App\Support\Response;

final class FileController
{
    public function put(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $fileId = (string) $params['id'];
        $name = rawurldecode((string) ($request->header('X-File-Name') ?? $fileId));
        $mime = (string) ($request->header('X-File-Mime') ?? '');
        $isImage = (string) ($request->header('X-File-Image') ?? '0') === '1';

        $meta = FileStore::putFromRequest((string) $user['id'], $fileId, mb_substr($name, 0, 200), $mime, $isImage);
        Response::json($meta, 201);
    }

    public function get(array $params): void
    {
        $user = Auth::requireUser();
        $row = FileStore::load((string) $user['id'], (string) $params['id']);
        if ($row === null) {
            throw new HttpError('文件不存在', 404);
        }
        // 文件 ID 为一次性 UUID，内容不会变化：允许浏览器 / AI 长期缓存，减少重复回源
        header('Cache-Control: private, max-age=31536000, immutable');
        Response::file(
            FileStore::path((string) $user['id'], (string) $params['id']),
            (string) $row['mime_type'],
            (string) $row['name'],
            true
        );
    }
}
