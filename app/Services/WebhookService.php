<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Payments\Gateways;
use App\Payments\PaymentStatusResult;
use App\Support\Clock;
use App\Support\Status;

/**
 * Inbound payment webhook pipeline (spec §15):
 *   1. verify the signature            (adapter; forged requests are stored as "invalid" and rejected 401)
 *   2. de-duplicate by event id        (retries/replays never process twice; a repeat only counts up `attempts` and stamps `last_attempt_at`)
 *   3. find the payment by gateway order id, check it belongs to this gateway   (an unknown reference is acknowledged and flagged)
 *   4. check transaction status, amount and order       (Settlement::settle)
 *   5. mark paid, create/activate the order, route it, update analytics
 *   6. record the transaction (webhook_events + order history)
 *   7. ask the gateway whether it agrees                (Risk::doubleCheck — see the setting `risk.webhook_double_check`)
 * We answer 2xx quickly (the gateway retries anything else with back-off) and run
 * the slow part — calling the SMM provider and the cross-check — after the response has been sent.
 *
 * `risk.webhook_double_check`:  off      the signed webhook is trusted as before;
 *                               flag     (default) credited on the signed webhook, then the gateway is asked after the response — a disagreement is flagged;
 *                               require  the webhook only says "look at this payment": the money is credited from OUR status lookup, and a gateway that
 *                                        cannot be asked right now gets a 503 so it delivers again.
 */
final class WebhookService
{
    public static function handle(Request $req, int $gatewayId): Response
    {
        $row = Gateways::row($gatewayId);
        if ($row === null) {
            return Response::text('unknown gateway', 404);
        }
        // Generous: gateways retry in bursts. This only stops floods.
        RateLimiter::enforce('webhook-ip', $req->ip(), 600, 600);

        $raw = $req->body();
        $adapter = Gateways::adapter($row);
        $event = $adapter->handleWebhook($raw, $req->headers);
        $hash = hash('sha256', $raw);
        $now = Clock::sql();

        if (!$event->signatureValid) {
            // dedupe_key stays NULL: a forged event must never "use up" a real event's id.
            Db::insert('webhook_events', [
                'gateway_id' => $gatewayId, 'provider' => $row['provider'], 'signature_valid' => 0, 'payload_hash' => $hash,
                'payload' => mb_substr($raw, 0, 1000), 'status' => 'invalid', 'error' => mb_substr($event->error, 0, 500),
                'ip' => $req->ip(), 'received_at' => $now,
            ]);
            Log::warning('Webhook rejected: bad signature', ['gateway' => $gatewayId, 'ip' => $req->ip()]);
            Risk::onInvalidSignature($gatewayId);
            return Response::text('invalid signature', 401);
        }
        if ($event->error !== '') {
            Db::insert('webhook_events', [
                'gateway_id' => $gatewayId, 'provider' => $row['provider'], 'signature_valid' => 1, 'payload_hash' => $hash,
                'payload' => mb_substr($raw, 0, 4000), 'status' => 'error', 'error' => mb_substr($event->error, 0, 500), 'ip' => $req->ip(), 'received_at' => $now,
            ]);
            return Response::text('invalid payload', 400);
        }

        $dedupe = mb_substr($row['provider'] . ':' . $gatewayId . ':' . $event->eventId, 0, 191);
        $eventRowId = self::registerEvent($row, $gatewayId, $event, $hash, $raw, $dedupe, $req->ip());
        if ($eventRowId === null) {
            return Response::text('duplicate', 200);       // already handled — acknowledge so retries stop
        }

        $payment = $event->gatewayOrderId !== null
            ? Db::one('SELECT * FROM payments WHERE gateway_order_id = ? AND gateway_id = ?', [$event->gatewayOrderId, $gatewayId])
            : null;
        if ($payment === null) {
            self::finish($eventRowId, 'ignored', 'No payment matches this order id.');
            Risk::record('unknown_reference', [
                'gateway_id' => $gatewayId, 'webhook_event_id' => $eventRowId, 'key' => 'g' . $gatewayId . ':' . substr(sha1((string) $event->gatewayOrderId), 0, 12),
                'details' => ['gateway_order_id' => (string) $event->gatewayOrderId, 'event_status' => $event->status],
            ]);
            return Response::text('ok', 200);            // 2xx: retrying cannot help
        }
        $upd = ['payment_id' => $payment['id'], 'order_id' => $payment['order_id']];

        switch ($event->status) {
            case PaymentStatusResult::SUCCESS:
                $mode = Risk::thresholds()['webhook_double_check'];
                if ($mode === 'require' && !in_array($payment['status'], Status::PAY_RECEIVED, true)) {
                    return self::creditFromLookup($payment, $eventRowId, $upd);
                }
                $s = Settlement::settle((int) $payment['id'], [
                    'source' => 'webhook', 'amount_paise' => $event->amountPaise, 'currency' => $event->currency, 'utr' => $event->utr, 'method' => $event->method,
                ]);
                $status = match ($s['result']) {
                    Settlement::SETTLED, Settlement::ALREADY => 'processed',
                    Settlement::MISMATCH => 'mismatch',
                    default => 'ignored',
                };
                self::finish($eventRowId, $status, $s['message'], $upd);
                $res = Response::text('ok', 200);
                if ($s['result'] === Settlement::SETTLED && $s['route'] === 'AUTO' && $s['order_id']) {
                    $orderId = (int) $s['order_id'];
                    $res->afterSend(static fn() => Fulfilment::submit($orderId));
                }
                if ($s['result'] === Settlement::SETTLED && $mode !== 'off') {
                    $paymentId = (int) $payment['id'];
                    $res->afterSend(static function () use ($paymentId): void {
                        try {
                            Risk::doubleCheck($paymentId);
                        } catch (\Throwable $e) {
                            Log::exception($e, 'Webhook cross-check failed', ['payment' => $paymentId]);
                        }
                    });
                }
                return $res;

            case PaymentStatusResult::FAILED:
            case PaymentStatusResult::EXPIRED:
                if (in_array($payment['status'], Status::PAY_RECEIVED, true)) {
                    // The gateway now says "failed" / "expired" for a payment we have recorded as paid: two sources disagree. Nothing is undone
                    // automatically — money may well have arrived — a person decides.
                    Risk::record('conflicting_status', [
                        'payment_id' => (int) $payment['id'], 'order_id' => (int) $payment['order_id'], 'gateway_id' => $gatewayId, 'webhook_event_id' => $eventRowId,
                        'key' => 'p' . $payment['id'] . ':' . strtolower($event->status) . '_after_paid',
                        'details' => ['kind' => strtolower($event->status) . '_after_paid', 'event_status' => $event->status, 'recorded_status' => (string) $payment['status'], 'recorded_via' => (string) $payment['verified_via']],
                    ]);
                    self::finish($eventRowId, 'mismatch', 'The gateway reported “' . $event->status . '” for a payment already recorded as paid; flagged for review.', $upd);
                    return Response::text('ok', 200);
                }
                if ($event->status === PaymentStatusResult::FAILED) {
                    PaymentService::fail((int) $payment['id'], 'The gateway reported that the payment failed.');
                    self::finish($eventRowId, 'processed', 'Payment marked failed.', $upd);
                } else {
                    PaymentService::expire((int) $payment['id'], true);
                    self::finish($eventRowId, 'processed', 'Payment marked expired.', $upd);
                }
                return Response::text('ok', 200);

            default:
                self::finish($eventRowId, 'ignored', 'Status “' . $event->status . '” needs no action.', $upd);
                return Response::text('ok', 200);
        }
    }

    /**
     * Mode "require": the webhook is only a hint to look. The payment is credited by the same server-to-server lookup the status poll uses, so a
     * forged or replayed notification can never credit anything on its own. A gateway that cannot be asked right now gets a 503 and delivers again.
     *
     * @param array<string,mixed> $payment
     * @param array<string,mixed> $upd
     */
    private static function creditFromLookup(array $payment, int $eventRowId, array $upd): Response
    {
        $r = PaymentService::checkGateway($payment);
        if ($r === null) {
            self::finish($eventRowId, 'error', 'The gateway could not be asked to confirm this payment yet; the delivery is retried.', $upd);
            return Response::text('try again later', 503);
        }
        $now = (string) Db::val('SELECT status FROM payments WHERE id = ?', [$payment['id']], '');
        if (in_array($now, Status::PAY_RECEIVED, true)) {
            self::finish($eventRowId, 'processed', 'Confirmed by the gateway’s own status lookup, then credited.', $upd);
            $res = Response::text('ok', 200);
            if ($r['route'] === 'AUTO' && $r['order_id']) {
                $orderId = (int) $r['order_id'];
                $res->afterSend(static fn() => Fulfilment::submit($orderId));
            }
            return $res;
        }
        if ($now === Status::PAY_MISMATCH) {
            self::finish($eventRowId, 'mismatch', 'The gateway’s own lookup does not match the order; flagged for review.', $upd);
            return Response::text('ok', 200);
        }
        Risk::record('conflicting_status', [
            'payment_id' => (int) $payment['id'], 'order_id' => (int) $payment['order_id'], 'gateway_id' => (int) $payment['gateway_id'], 'webhook_event_id' => $eventRowId,
            'key' => 'p' . $payment['id'] . ':webhook_not_confirmed',
            'details' => ['kind' => 'webhook_not_confirmed', 'gateway_status' => (string) ($r['gateway_status'] ?? ''), 'recorded_status' => $now, 'mode' => 'require'],
        ]);
        self::finish($eventRowId, 'ignored', 'The webhook said paid but the gateway’s own lookup does not confirm it; nothing was credited.', $upd);
        return Response::text('ok', 200);
    }

    /**
     * Insert the event, or detect a repeat. Returns the row id to process, or
     * null when this event was already handled (processed / ignored / mismatch).
     */
    private static function registerEvent(array $row, int $gatewayId, \App\Payments\WebhookEvent $event, string $hash, string $raw, string $dedupe, string $ip): ?int
    {
        $n = Db::exec(
            'INSERT IGNORE INTO webhook_events (gateway_id, provider, event_id, event_type, dedupe_key, signature_valid, payload_hash, payload, status, ip, received_at) '
            . "VALUES (?, ?, ?, ?, ?, 1, ?, ?, 'received', ?, ?)",
            [$gatewayId, $row['provider'], $event->eventId, $event->status, $dedupe, $hash, mb_substr($raw, 0, 4000), $ip, Clock::sql()]
        );
        if ($n === 1) {
            return Db::lastId();
        }
        $existing = Db::one('SELECT id, status FROM webhook_events WHERE dedupe_key = ?', [$dedupe]);
        if ($existing === null) {
            return null;
        }
        Db::exec('UPDATE webhook_events SET attempts = attempts + 1, last_attempt_at = ? WHERE id = ?', [Clock::sql(), $existing['id']]);
        // An earlier delivery that crashed half-way may be retried; settle() is idempotent so this is safe.
        return in_array($existing['status'], ['received', 'error'], true) ? (int) $existing['id'] : null;
    }

    /** @param array<string,mixed> $extra */
    private static function finish(int $id, string $status, string $message, array $extra = []): void
    {
        Db::update('webhook_events', ['status' => $status, 'error' => $status === 'processed' ? null : mb_substr($message, 0, 500), 'processed_at' => Clock::sql()] + $extra, 'id = ?', [$id]);
    }
}
