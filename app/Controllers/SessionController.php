<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SessionStore;
use App\Support\Auth;
use App\Support\HttpError;
use App\Support\Request;
use App\Support\Response;

final class SessionController
{
    public function index(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $limit = (int) ($request->query('limit') ?? 30);
        $cursor = (string) ($request->query('cursor') ?? '');
        $beforeUpdated = null;
        $beforeId = null;
        // 游标格式：<updatedMs>_<sessionId>
        if ($cursor !== '' && preg_match('/^([0-9]{1,16})_([0-9]{1,20})$/', $cursor, $m)) {
            $beforeUpdated = (int) $m[1];
            $beforeId = $m[2];
        }
        Response::json(SessionStore::summaries((string) $user['id'], $limit, $beforeUpdated, $beforeId));
    }

    public function show(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $session = SessionStore::load((string) $user['id'], (string) $params['id']);
        if ($session === null) {
            throw new HttpError('会话不存在', 404);
        }
        Response::json($session);
    }

    public function save(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $session = $request->json();
        SessionStore::save((string) $user['id'], (string) $params['id'], $session);
        Response::json(['ok' => true]);
    }

    public function rename(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $body = $request->json();
        SessionStore::rename((string) $user['id'], (string) $params['id'], (string) ($body['title'] ?? ''));
        Response::json(['ok' => true]);
    }

    public function delete(array $params): void
    {
        $user = Auth::requireUser();
        SessionStore::delete((string) $user['id'], (string) $params['id']);
        Response::json(['ok' => true]);
    }
}
