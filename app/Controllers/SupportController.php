<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\SupportAssistant;

/**
 * The customer-facing support assistant (master prompt §7). Stateless except for the conversation key the browser keeps
 * (`localStorage`, like an order token) — there is no account and no admin session involved. An order is only ever read through
 * its 128-bit access token (`order_token`, the same token `/order/{token}` requires); a bare order id proves nothing and is
 * never accepted.
 */
final class SupportController
{
    /** GET /api/support/config — lets the widget hide itself when the owner switched support off, with no POST needed to find out. */
    public function config(Request $req, array $p): Response
    {
        return Response::json(['ok' => true, 'enabled' => SupportAssistant::enabled()]);
    }

    public function start(Request $req, array $p): Response
    {
        return Response::json(['ok' => true] + SupportAssistant::start($req));
    }

    public function message(Request $req, array $p): Response
    {
        $in = $req->json();
        $text = is_string($in['text'] ?? null) ? $in['text'] : '';
        $orderToken = is_string($in['order_token'] ?? null) ? $in['order_token'] : null;
        return Response::json(['ok' => true] + SupportAssistant::message($req, (string) $p['key'], $text, $orderToken));
    }

    public function history(Request $req, array $p): Response
    {
        return Response::json(['ok' => true, 'messages' => SupportAssistant::history($req, (string) $p['key'])]);
    }
}
