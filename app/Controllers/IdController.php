<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;
use App\Support\Snowflake;

final class IdController
{
    public function create(array $params, Request $request): void
    {
        Auth::requireUser();
        $body = $request->json();
        $count = (int) ($body['count'] ?? 200);
        $count = max(1, min($count, 1000));
        Response::json(['ids' => Snowflake::instance()->nextBatch($count)]);
    }
}
