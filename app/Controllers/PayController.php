<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Fulfilment;
use App\Services\Orders;
use App\Services\PaymentService;
use App\Services\Settings;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Qr;
use App\Support\Status;

/**
 * The payment page (/pay/{ref}), its QR image and the status endpoint the page polls.
 * {ref} is a 128-bit random capability: knowing it is what authorises viewing a payment.
 */
final class PayController
{
    public function show(Request $req, array $p): Response
    {
        RateLimiter::enforce('pay-view', $req->ip(), 90, 60);
        $payment = Orders::paymentByRef($p['ref']) ?? throw new HttpException(404);
        $res = PaymentService::refresh($payment);       // verify with the gateway (rate-limited) before rendering
        $payment = $res['payment'];
        $order = Orders::byId((int) $payment['order_id']);
        $site = Settings::site();
        $state = self::state($payment);
        $now = Clock::now();

        $pay = [
            'ref' => $payment['ref'], 'state' => $state, 'amount' => Money::inr((int) $payment['amount_paise']), 'amount_paise' => (int) $payment['amount_paise'],
            'service' => $order['service_title'], 'order_id' => $order['public_id'],
            'expires_at' => (int) Clock::fromSql($payment['expires_at']) * 1000, 'server_now' => $now * 1000, 'ttl' => Orders::PAYMENT_TTL,
            'qr_url' => url('pay/' . $payment['ref'] . '/qr.png'), 'download_url' => url('pay/' . $payment['ref'] . '/qr.png') . '?download=1',
            'pay_url' => $state === 'pending' ? (string) $payment['payment_url'] : '#',
            'track_url' => url('order/' . $order['access_token']), 'status_url' => url('api/pay/' . $payment['ref'] . '/status'),
            'instructions' => $site['payment_instructions'], 'returned' => !empty($req->query['returned']) || !empty($req->query['status']),   // the gateway's redirect adds status=…&order_id=…
        ];
        $html = View::render('layouts/site', [
            'content' => View::render('pay/show', ['site' => $site, 'pay' => $pay]),
            'site' => $site, 'title' => ($state === 'paid' ? 'Payment successful' : 'Pay securely') . ' — ' . $site['name'],
            'page' => 'pay', 'noindex' => true, 'scripts' => ['pay'], 'shop' => false,
        ]);
        $response = Response::html($html)->header('Cache-Control', 'no-store');
        $this->afterPayment($response, $res);
        return $response;
    }

    /** JSON status for the polling page. */
    public function status(Request $req, array $p): Response
    {
        RateLimiter::enforce('pay-status', $req->ip(), 150, 60);
        $payment = Orders::paymentByRef($p['ref']) ?? throw new HttpException(404);
        $res = PaymentService::refresh($payment);
        $payment = $res['payment'];
        $order = Orders::byId((int) $payment['order_id']);
        $state = self::state($payment);
        $response = Response::json([
            'ok' => true, 'state' => $state, 'payment_status' => $payment['status'], 'order_status' => $order['status'],
            'order_label' => Status::customerLabel((string) $order['status'], (string) $order['mode']),
            'expires_at' => (int) Clock::fromSql($payment['expires_at']) * 1000, 'server_now' => Clock::now() * 1000,
            'late' => (bool) $payment['late'],
        ]);
        $this->afterPayment($response, $res);
        return $response;
    }

    /** The QR image. Served only while the payment is still open — an expired or paid session never shows a usable QR. */
    public function qr(Request $req, array $p): Response
    {
        RateLimiter::enforce('pay-qr', $req->ip(), 60, 60);
        $payment = Orders::paymentByRef($p['ref']) ?? throw new HttpException(404);
        if (self::state($payment) !== 'pending' || empty($payment['qr_payload'])) {
            throw new HttpException(410, 'This payment session is no longer active.', 'gone');
        }
        $order = Orders::byId((int) $payment['order_id']);
        $png = Qr::png((string) $payment['qr_payload'], 10, 4, 'M');
        $res = Response::binary($png, 'image/png', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        if (!empty($req->query['download'])) {
            $res->header('Content-Disposition', 'attachment; filename="' . $order['public_id'] . '-payment-qr.png"');
        } else {
            $res->header('Content-Disposition', 'inline');
        }
        return $res;
    }

    /** Collapses the many payment states into what the page can show. */
    public static function state(array $payment): string
    {
        if (in_array($payment['status'], Status::PAY_RECEIVED, true)) {
            return 'paid';
        }
        return match ($payment['status']) {
            Status::PAY_MISMATCH => 'review',
            Status::PAY_EXPIRED => 'expired',
            Status::PAY_FAILED => 'failed',
            default => (int) Clock::fromSql((string) $payment['expires_at']) <= Clock::now() ? 'expired' : 'pending',
        };
    }

    /** If this request just confirmed an AUTO payment, call the SMM provider AFTER the response is sent. */
    private function afterPayment(Response $response, array $res): void
    {
        if ($res['route'] === 'AUTO' && $res['order_id']) {
            $orderId = (int) $res['order_id'];
            $response->afterSend(static fn() => Fulfilment::submit($orderId));
        }
    }
}
