<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\FileStore;
use App\Services\ImportService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Request;
use App\Support\Response;

final class HistoryController
{
    public function clear(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        FileStore::clearStaging((string) $user['id']);
        Response::json(['ok' => true]);
    }

    public function addSession(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $session = $request->json();
        $id = (string) ($session['id'] ?? '');
        if ($id === '' || strlen($id) > 64) {
            throw new HttpError('导入会话 ID 非法', 422);
        }
        if (!isset($session['messages']) || !is_array($session['messages'])) {
            throw new HttpError('导入会话数据不完整', 422);
        }
        $data = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($data === false) {
            throw new HttpError('导入会话序列化失败', 422);
        }
        $stmt = Database::pdo()->prepare(
            'REPLACE INTO staging_sessions (user_id, id, title, data, created_ms) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'],
            $id,
            mb_substr((string) ($session['title'] ?? ''), 0, 200),
            $data,
            (int) floor(microtime(true) * 1000),
        ]);
        Response::json(['ok' => true], 201);
    }

    public function addFile(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $fileId = (string) $params['id'];
        $name = rawurldecode((string) ($request->header('X-File-Name') ?? $fileId));
        $mime = (string) ($request->header('X-File-Mime') ?? '');
        $isImage = (string) ($request->header('X-File-Image') ?? '0') === '1';
        $meta = FileStore::putStagingFromRequest((string) $user['id'], $fileId, mb_substr($name, 0, 200), $mime, $isImage);
        Response::json($meta, 201);
    }

    public function updateFileTypes(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $types = $request->json();
        if ($types === []) {
            Response::json(['ok' => true]);
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('UPDATE staging_files SET mime_type = ? WHERE user_id = ? AND id = ?');
        foreach ($types as $fileId => $mime) {
            if (is_string($fileId) && is_string($mime)) {
                $stmt->execute([$mime, $user['id'], $fileId]);
            }
        }

        $stmt = $pdo->prepare('SELECT id, data FROM staging_sessions WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $update = $pdo->prepare('UPDATE staging_sessions SET data = ? WHERE user_id = ? AND id = ?');
        foreach ($stmt->fetchAll() as $row) {
            $session = json_decode((string) $row['data'], true);
            if (!is_array($session) || !isset($session['messages']) || !is_array($session['messages'])) {
                continue;
            }
            foreach ($session['messages'] as &$message) {
                if (!is_array($message) || !isset($message['files']) || !is_array($message['files'])) {
                    continue;
                }
                foreach ($message['files'] as &$file) {
                    $fileId = is_array($file) ? (string) ($file['id'] ?? '') : '';
                    if ($fileId !== '' && isset($types[$fileId]) && is_string($types[$fileId])) {
                        $file['mimeType'] = $types[$fileId];
                    }
                }
                unset($file);
            }
            unset($message);
            $data = json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($data !== false) {
                $update->execute([$data, $user['id'], $row['id']]);
            }
        }

        Response::json(['ok' => true]);
    }

    public function commit(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $sessions = ImportService::commit((string) $user['id']);
        Response::json(['sessions' => $sessions]);
    }
}
