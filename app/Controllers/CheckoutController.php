<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Idempotency;
use App\Core\Request;
use App\Core\Response;
use App\Services\Orders;
use App\Services\Tracking;

/**
 * POST /api/checkout  { service_id, target, quantity? }  +  Idempotency-Key header.
 * `quantity` is only meaningful for a per_unit service (App\Services\OrderCalculator) and is
 * itself fully re-validated server-side — everything else (price, mode, provider, cost, and the
 * quantity for a fixed_package service) is read from the database, never from the request.
 */
final class CheckoutController
{
    public function create(Request $req, array $p): Response
    {
        $in = $req->json();
        $key = (string) ($req->header('idempotency-key') ?? '');
        $visitor = Tracking::context($req);
        $hash = hash('sha256', json_encode([$in['service_id'] ?? null, $in['target'] ?? null, $in['quantity'] ?? null]));

        [$status, $body] = Idempotency::run('checkout', $key, $hash, static function () use ($req, $in, $visitor): array {
            $r = Orders::checkout($req, $in, $visitor);
            $payment = Orders::paymentByRef($r['ref']);
            $order = Orders::byId((int) $payment['order_id']);
            return [200, [
                'ok' => true, 'ref' => $r['ref'], 'order_id' => $r['order_id'],
                'url' => Orders::continueUrl($payment),      // our payment page with the QR — or, when the gateway gave no QR, its own checkout
                'token' => $order['access_token'],
            ]];
        });
        return Response::json($body, $status);
    }
}
