<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\WebhookService;

final class WebhookController
{
    public function payments(Request $req, array $p): Response
    {
        return WebhookService::handle($req, (int) $p['id']);
    }
}
