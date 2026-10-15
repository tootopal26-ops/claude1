<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;
use App\Payments\Gateways;
use App\Payments\PaymentStatusResult;
use App\Support\Clock;
use App\Support\Status;

/**
 * Keeps payment sessions honest: expires them after 5 minutes, and verifies
 * "pending" ones with the gateway server-to-server (never trusting the browser).
 */
final class PaymentService
{
    private const POLL_MIN_INTERVAL = 4;     // seconds between gateway lookups for one payment
    private const RECONCILE_WINDOW  = 3600;  // keep re-checking expired sessions for 1 hour (late payments)

    /**
     * Bring a payment up to date. Safe to call on every status poll.
     *
     * @return array{payment:array<string,mixed>,route:?string,order_id:?int}
     */
    public static function refresh(array $payment, bool $callGateway = true): array
    {
        $route = null;
        $orderId = null;
        $id = (int) $payment['id'];

        if (in_array($payment['status'], [Status::PAY_PENDING], true) && $callGateway) {
            $now = Clock::now();
            $claimed = Db::exec(
                "UPDATE payments SET last_polled_at = ?, poll_count = poll_count + 1 WHERE id = ? AND status = 'pending' AND (last_polled_at IS NULL OR last_polled_at <= ?)",
                [Clock::sql($now), $id, Clock::sql($now - self::POLL_MIN_INTERVAL)]
            ) === 1;
            if ($claimed) {
                $r = self::checkGateway($payment);
                if ($r !== null) {
                    $route = $r['route'];
                    $orderId = $r['order_id'];
                }
            }
        }

        $fresh = Db::one('SELECT * FROM payments WHERE id = ?', [$id]) ?? $payment;
        if ($fresh['status'] === Status::PAY_PENDING && (int) Clock::fromSql($fresh['expires_at']) <= Clock::now()) {
            self::expire($id);
            $fresh = Db::one('SELECT * FROM payments WHERE id = ?', [$id]) ?? $fresh;
        }
        return ['payment' => $fresh, 'route' => $route, 'order_id' => $orderId];
    }

    /**
     * Ask the gateway about this payment and act on the answer.
     *
     * @return array{route:?string,order_id:?int}|null null when the gateway could not be reached
     */
    public static function checkGateway(array $payment): ?array
    {
        $row = $payment['gateway_id'] !== null ? Gateways::row((int) $payment['gateway_id']) : null;
        if ($row === null) {
            return null;
        }
        try {
            $r = Gateways::adapter($row)->verifyPayment((string) $payment['gateway_order_id'], (int) $payment['amount_paise']);
        } catch (\Throwable $e) {
            Log::exception($e, 'Gateway status check failed', ['payment' => $payment['id']]);
            return null;
        }
        if (!$r->reachable) {
            return null;
        }
        $id = (int) $payment['id'];
        switch ($r->status) {
            case PaymentStatusResult::SUCCESS:
                $s = Settlement::settle($id, ['source' => 'poll', 'amount_paise' => $r->amountPaise, 'currency' => $r->currency, 'utr' => $r->utr, 'method' => $r->method]);
                return ['route' => $s['route'], 'order_id' => $s['order_id']];
            case PaymentStatusResult::FAILED:
                self::fail($id, 'The payment failed at the gateway.');
                break;
            case PaymentStatusResult::EXPIRED:
                self::expire($id, true);
                break;
        }
        return ['route' => null, 'order_id' => null];
    }

    /**
     * Expire a payment session (and its unpaid order). No-op unless it is still
     * pending and past its deadline — or $force (gateway said it expired).
     */
    public static function expire(int $paymentId, bool $force = false): bool
    {
        $done = Db::tx(static function () use ($paymentId, $force): ?array {
            $p = Db::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if ($p === null || !in_array($p['status'], [Status::PAY_PENDING, Status::PAY_CREATING], true)) {
                return null;
            }
            if (!$force && (int) Clock::fromSql($p['expires_at']) > Clock::now()) {
                return null;
            }
            Db::update('payments', ['status' => Status::PAY_EXPIRED, 'updated_at' => Clock::sql()], 'id = ?', [$paymentId]);
            Db::update('orders', ['payment_status' => 'expired'], "id = ? AND status = 'PENDING_PAYMENT'", [$p['order_id']]);
            Orders::transition((int) $p['order_id'], Status::EXPIRED, ['actor' => 'system', 'note' => 'Payment session expired', 'soft' => true]);
            return ['day' => $p['created_day']];
        });
        if ($done !== null) {
            Analytics::markDirty((string) $done['day']);
            return true;
        }
        return false;
    }

    public static function fail(int $paymentId, string $reason): void
    {
        $done = Db::tx(static function () use ($paymentId, $reason): ?array {
            $p = Db::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if ($p === null || !in_array($p['status'], [Status::PAY_PENDING, Status::PAY_CREATING], true)) {
                return null;
            }
            Db::update('payments', ['status' => Status::PAY_FAILED, 'failure_reason' => mb_substr($reason, 0, 255), 'updated_at' => Clock::sql()], 'id = ?', [$paymentId]);
            Db::update('orders', ['payment_status' => 'failed', 'last_error' => mb_substr($reason, 0, 255)], "id = ? AND status = 'PENDING_PAYMENT'", [$p['order_id']]);
            Orders::transition((int) $p['order_id'], Status::FAILED, ['actor' => 'system', 'note' => $reason, 'soft' => true]);
            return ['day' => $p['created_day']];
        });
        if ($done !== null) {
            Analytics::markDirty((string) $done['day']);
        }
    }

    /**
     * Cron sweep:
     *  1. sessions stuck in "creating" (crashed request) → failed;
     *  2. pending sessions past their deadline → ask the gateway one last time, then expire;
     *  3. recently expired sessions → re-check a few times so a late payment whose
     *     webhook never arrived is still credited.
     *
     * @return array{expired:int,failed:int,settled:int}
     */
    public static function sweep(int $maxGatewayCalls = 20): array
    {
        $stats = ['expired' => 0, 'failed' => 0, 'settled' => 0];
        $now = Clock::now();

        foreach (Db::all("SELECT id FROM payments WHERE status = 'creating' AND created_at < ? LIMIT 100", [Clock::sql($now - 120)]) as $r) {
            self::fail((int) $r['id'], 'Payment session was never created.');
            $stats['failed']++;
        }

        $due = Db::all("SELECT * FROM payments WHERE status = 'pending' AND expires_at <= ? ORDER BY id LIMIT 200", [Clock::sql($now)]);
        $calls = 0;
        foreach ($due as $p) {
            if ($calls < $maxGatewayCalls) {
                $calls++;
                $r = self::checkGateway($p);
                if ($r !== null && $r['route'] !== null) {
                    $stats['settled']++;
                    if ($r['route'] === 'AUTO' && $r['order_id']) {
                        Fulfilment::submit((int) $r['order_id']);
                    }
                    continue;
                }
            }
            if (self::expire((int) $p['id'])) {
                $stats['expired']++;
            }
        }

        $recent = Db::all(
            "SELECT * FROM payments WHERE status = 'expired' AND expires_at >= ? AND poll_count < 6 AND (last_polled_at IS NULL OR last_polled_at <= ?) ORDER BY id DESC LIMIT 10",
            [Clock::sql($now - self::RECONCILE_WINDOW), Clock::sql($now - 90)]
        );
        foreach ($recent as $p) {
            Db::exec('UPDATE payments SET last_polled_at = ?, poll_count = poll_count + 1 WHERE id = ?', [Clock::sql($now), $p['id']]);
            $r = self::checkGateway($p);
            if ($r !== null && $r['route'] !== null) {
                $stats['settled']++;
                if ($r['route'] === 'AUTO' && $r['order_id']) {
                    Fulfilment::submit((int) $r['order_id']);
                }
            }
        }
        return $stats;
    }
}
