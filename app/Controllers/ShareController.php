<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ShareService;
use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

final class ShareController
{
    /** 我的分享列表 */
    public function index(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        Response::json(ShareService::listMine((string) $user['id']));
    }

    /** 为会话创建分享链接 */
    public function create(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        $body = $request->json();
        $sessionId = (string) ($body['sessionId'] ?? '');
        if ($sessionId === '') {
            Response::error('缺少会话 ID', 422);
        }
        $messageIds = is_array($body['messageIds'] ?? null) ? $body['messageIds'] : [];
        Response::json(ShareService::create((string) $user['id'], $sessionId, $messageIds));
    }

    /** 撤销自己的分享链接 */
    public function revoke(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        ShareService::revoke((string) $user['id'], (string) $params['token']);
        Response::json(['ok' => true]);
    }

    /** 公开读取分享快照，无需登录 */
    public function show(array $params, Request $request): void
    {
        Response::json(ShareService::publicView((string) $params['token']));
    }

    /** 分享页「继续对话」：复制到当前账号并返回新会话 */
    public function fork(array $params, Request $request): void
    {
        $user = Auth::requireUser();
        Response::json(ShareService::fork((string) $user['id'], (string) $params['token']));
    }
}
