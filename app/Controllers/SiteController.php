<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SiteSettings;
use App\Support\Response;

final class SiteController
{
    public function show(): void
    {
        Response::json(SiteSettings::publicInfo());
    }
}
