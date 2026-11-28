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
        $limit = (int) ($request->query('limit') ?? 20);
        $cursor = (string) ($request->query('cursor') ?? '');
        $beforeUpdated = null;
        $beforeId = null;
        $beforePinned = null;
        // 游标格式：<pinned>_<updatedMs>_<sessionId>；兼容旧格式 <updatedMs>_<sessionId>
        if ($cursor !== '' && preg_match('/^([0-9])_([0-9]{1,16})_([0-9]{1,20})$/', $cursor, $m)) {
            $beforePinned = (int) $m[1];
            $beforeUpdated = (int) $m[2];
            $beforeId = $m[3];
        } elseif ($cursor !== '' && preg_match('/^([0-9]{1,16})_([0-9]{1,20})$/', $cursor, $m)) {
            $beforeUpdated = (int) $m[1];
            $beforeId = $m[2];
        }
        Response::json(SessionStore::summaries((string) $user['id'], $limit, $beforeUpdated, $beforeId, $beforePinned));
    }

    /** 搜索当前用户的会话：标题与消息正文，返回命中位置便于前端跳转 */
    public function search(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $query = (string) ($request->query('q') ?? '');
        Response::json(SessionStore::search((string) $user['id'], $query));
    }

    /** 会话精简大纲：右侧导航栏按窗口加载用户提问预览，每次最多 20 条 */
    public function outline(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $session = SessionStore::load((string) $user['id'], (string) $params['id']);
        if ($session === null) {
            throw new HttpError('会话不存在', 404);
        }
        $limit = (int) ($request->query('limit') ?? 20);
        $offset = (int) ($request->query('offset') ?? 0);
        $around = (string) ($request->query('around') ?? '');
        Response::json(SessionStore::outline($session, $offset, $limit, $around !== '' ? $around : null));
    }

    public function show(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $session = SessionStore::load((string) $user['id'], (string) $params['id']);
        if ($session === null) {
            throw new HttpError('会话不存在', 404);
        }
        $limit = $request->query('limit');
        if ($limit === null || $limit === '') {
            Response::json($session);
            return;
        }
        // around：定位到指定消息所在的窗口（搜索结果跳转）
        $around = (string) ($request->query('around') ?? '');
        if ($around !== '') {
            $page = SessionStore::pageAround($session, $around, (int) $limit);
            Response::json($page['session'] + [
                'hasMore' => $page['hasMore'],
                'hasNewer' => $page['hasNewer'],
                'nextCursor' => $page['hasMore'] ? (string) ($page['session']['messages'][0]['id'] ?? '') : null,
            ]);
            return;
        }
        // after：从窗口末尾继续向后加载（跳转窗口后向下滚动）
        $after = (string) ($request->query('after') ?? '');
        if ($after !== '') {
            $page = SessionStore::pageAfter($session, $after, (int) $limit);
            Response::json($page['session'] + [
                'hasNewer' => $page['hasNewer'],
            ]);
            return;
        }
        $before = $request->query('before');
        $page = SessionStore::page($session, $before !== '' ? $before : null, (int) $limit);
        Response::json($page['session'] + [
            'hasMore' => $page['hasMore'],
            'hasNewer' => $page['hasNewer'],
            'nextCursor' => $page['hasMore'] ? (string) ($page['session']['messages'][0]['id'] ?? '') : null,
        ]);
    }

    public function save(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $session = $request->json();
        $partial = !empty($session['partialMessages']);
        unset($session['partialMessages']);
        SessionStore::save((string) $user['id'], (string) $params['id'], $session, $partial);
        Response::json(['ok' => true]);
    }

    public function rename(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $body = $request->json();
        $id = (string) $params['id'];
        if (array_key_exists('pinned', $body)) {
            SessionStore::setPinned((string) $user['id'], $id, (bool) $body['pinned']);
        }
        if (array_key_exists('title', $body)) {
            SessionStore::rename((string) $user['id'], $id, (string) $body['title']);
        }
        Response::json(['ok' => true]);
    }

    public function delete(array $params): void
    {
        $user = Auth::requireUser();
        SessionStore::delete((string) $user['id'], (string) $params['id']);
        Response::json(['ok' => true]);
    }
}
