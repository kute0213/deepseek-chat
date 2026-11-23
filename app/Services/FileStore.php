<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpError;

final class FileStore
{
    /**
     * 上传目录按「用途 / 用户 ID / 文件 ID」严格分层：
     * uploads/attachments/{userId}/{fileId}
     * uploads/staging/{userId}/{fileId}
     */
    public static function attachmentDir(string $userId): string
    {
        return APP_ROOT . '/uploads/attachments/' . $userId;
    }

    public static function stagingDir(string $userId): string
    {
        return APP_ROOT . '/uploads/staging/' . $userId;
    }

    public static function path(string $userId, string $fileId): string
    {
        return self::attachmentDir($userId) . '/' . $fileId;
    }

    public static function assertId(string $fileId): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $fileId) || str_contains($fileId, '..')) {
            throw new HttpError('附件 ID 非法', 400);
        }
    }

    public static function maxBytes(): int
    {
        return max(1, (int) SiteSettings::get('max_upload_mb', '64')) * 1024 * 1024;
    }

    /** 从请求体写入用户附件目录，返回元数据。 */
    public static function putFromRequest(string $userId, string $fileId, string $name, string $mime, bool $isImage): array
    {
        self::assertId($fileId);
        $dir = self::attachmentDir($userId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpError('无法创建附件目录', 500);
        }
        $target = $dir . '/' . $fileId;
        $tmp = $target . '.part';
        $in = fopen('php://input', 'rb');
        $out = fopen($tmp, 'wb');
        if ($in === false || $out === false) {
            throw new HttpError('无法写入附件', 500);
        }
        $size = 0;
        $limit = self::maxBytes();
        while (!feof($in)) {
            $chunk = fread($in, 8192);
            if ($chunk === false) {
                break;
            }
            $size += strlen($chunk);
            if ($size > $limit) {
                fclose($in);
                fclose($out);
                @unlink($tmp);
                throw new HttpError('文件超过大小限制', 413);
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        if ($size === 0) {
            @unlink($tmp);
            throw new HttpError('文件内容为空', 400);
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new HttpError('保存附件失败', 500);
        }

        $now = (int) floor(microtime(true) * 1000);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO files (user_id, id, name, size, is_image, mime_type, server_id, image_size_checked, created_ms)
             VALUES (?, ?, ?, ?, ?, ?, NULL, 0, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), size = VALUES(size), is_image = VALUES(is_image), mime_type = VALUES(mime_type)'
        );
        $stmt->execute([$userId, $fileId, $name, $size, $isImage ? 1 : 0, $mime, $now]);

        return [
            'id' => $fileId,
            'name' => $name,
            'size' => $size,
            'isImage' => $isImage,
            'mimeType' => $mime,
        ];
    }

    public static function putStagingFromRequest(string $userId, string $fileId, string $name, string $mime, bool $isImage): array
    {
        self::assertId($fileId);
        $dir = self::stagingDir($userId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpError('无法创建暂存目录', 500);
        }
        $target = $dir . '/' . $fileId;
        $tmp = $target . '.part';
        $in = fopen('php://input', 'rb');
        $out = fopen($tmp, 'wb');
        if ($in === false || $out === false) {
            throw new HttpError('无法写入暂存附件', 500);
        }
        $size = 0;
        $limit = self::maxBytes();
        while (!feof($in)) {
            $chunk = fread($in, 8192);
            if ($chunk === false) {
                break;
            }
            $size += strlen($chunk);
            if ($size > $limit) {
                fclose($in);
                fclose($out);
                @unlink($tmp);
                throw new HttpError('文件超过大小限制', 413);
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        if ($size === 0) {
            @unlink($tmp);
            throw new HttpError('文件内容为空', 400);
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new HttpError('保存暂存附件失败', 500);
        }

        $stmt = Database::pdo()->prepare(
            'REPLACE INTO staging_files (user_id, id, name, size, is_image, mime_type, created_ms) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $fileId, $name, $size, $isImage ? 1 : 0, $mime, (int) floor(microtime(true) * 1000)]);

        return ['id' => $fileId, 'name' => $name, 'size' => $size, 'mimeType' => $mime, 'isImage' => $isImage];
    }

    public static function load(string $userId, string $fileId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM files WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $fileId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function clearStaging(string $userId): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM staging_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $stmt = Database::pdo()->prepare('DELETE FROM staging_files WHERE user_id = ?');
        $stmt->execute([$userId]);
        self::removeDir(self::stagingDir($userId));
    }

    public static function removeFile(string $userId, string $fileId): void
    {
        @unlink(self::path($userId, $fileId));
        self::pruneDir(self::attachmentDir($userId));
    }

    /** 会话删除后附件目录可能变空，顺手移除，避免留下空壳目录 */
    public static function pruneDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries !== false && count($entries) === 2) {
            @rmdir($dir);
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
