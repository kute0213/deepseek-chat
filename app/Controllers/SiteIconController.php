<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SiteIconService;
use App\Support\Request;

final class SiteIconController
{
    /** AI 联网搜索结果站点图标：服务端下载并缓存，前端不再直接请求第三方或相对地址 */
    public function show(array $params, Request $request): void
    {
        SiteIconService::serve(
            (string) ($request->query('url') ?? ''),
            (string) ($request->query('page') ?? '')
        );
    }
}
