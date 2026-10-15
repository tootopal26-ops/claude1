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
 * POST /api/checkout  { service_id, target }  +  Idempotency-Key header.
 * Only the service id and what the customer typed are accepted. Everything
 * else (price, quantity, mode, provider, cost) is read from the database.
 */
final class CheckoutController
{
    public function create(Request $req, array $p): Response
    {
        $in = $req->json();
        $key = (string) ($req->header('idempotency-key') ?? '');
        $visitor = Tracking::context($req);
        $hash = hash('sha256', json_encode([$in['service_id'] ?? null, $in['target'] ?? null]));

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
