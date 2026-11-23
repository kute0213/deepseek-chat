<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Captcha;
use App\Support\Request;
use App\Support\Response;

final class CaptchaController
{
    public function show(array $params, Request $request): void
    {
        header('Cache-Control: no-store');
        Response::json(Captcha::generate(Request::ip()));
    }
}
