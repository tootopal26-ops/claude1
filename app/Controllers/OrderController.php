<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Orders;
use App\Services\Settings;
use App\Support\Clock;
use App\Support\Money;

/** Customer order tracking. The 128-bit token in the URL is the only credential — there is no way to enumerate other people's orders. */
final class OrderController
{
    public function show(Request $req, array $p): Response
    {
        RateLimiter::enforce('order-view', $req->ip(), 90, 60);
        $order = Orders::byToken($p['token']) ?? throw new HttpException(404);
        $site = Settings::site();
        $html = View::render('layouts/site', [
            'content' => View::render('order/show', ['site' => $site, 'o' => Orders::customerView($order), 'token' => $p['token']]),
            'site' => $site, 'title' => 'Order ' . $order['public_id'] . ' — ' . $site['name'], 'page' => 'order', 'noindex' => true, 'scripts' => ['order'],
        ]);
        return Response::html($html)->header('Cache-Control', 'no-store');
    }

    public function status(Request $req, array $p): Response
    {
        RateLimiter::enforce('order-status', $req->ip(), 120, 60);
        $order = Orders::byToken($p['token']) ?? throw new HttpException(404);
        return Response::json(['ok' => true, 'order' => Orders::customerView($order)]);
    }

    /** "Your orders" drawer: statuses for tokens kept in this browser (max 10). */
    public function lookup(Request $req, array $p): Response
    {
        RateLimiter::enforce('order-lookup', $req->ip(), 30, 60);
        $tokens = $req->json()['tokens'] ?? [];
        if (!is_array($tokens)) {
            throw new HttpException(422, 'Invalid request.');
        }
        $out = [];
        foreach (array_slice(array_values(array_unique(array_filter($tokens, 'is_string'))), 0, 10) as $token) {
            $o = Orders::byToken($token);
            if ($o === null) {
                continue;
            }
            $view = Orders::customerView($o);
            $view['track_url'] = url('order/' . $token);
            $view['token'] = $token;
            $view['pay_url'] = null;
            if ($o['status'] === 'PENDING_PAYMENT') {
                $pay = \App\Core\Db::one("SELECT ref, expires_at FROM payments WHERE order_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$o['id']]);
                if ($pay && (int) Clock::fromSql($pay['expires_at']) > Clock::now()) {
                    $view['pay_url'] = url('pay/' . $pay['ref']);
                }
            }
            $out[] = $view;
        }
        return Response::json(['ok' => true, 'orders' => $out]);
    }
}
