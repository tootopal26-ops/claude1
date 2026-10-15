<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Status;
use App\Support\Tz;

/**
 * Turns "the gateway says this payment succeeded" into a paid order — exactly once.
 *
 * Called from three places that can race each other: the signed webhook, the
 * status poller and an admin's manual confirmation. The payment row is locked
 * (SELECT … FOR UPDATE) for the whole decision, so whichever arrives second
 * finds the payment already settled and does nothing. A payment can therefore
 * never be counted twice, however many times it is delivered.
 *
 * Checks before any money is recognised:
 *   1. the reported amount equals the amount WE stored for this payment;
 *   2. the bank reference (UTR) has not been used by another payment;
 *   3. the order is still in a state that can be paid.
 */
final class Settlement
{
    public const SETTLED  = 'settled';
    public const ALREADY  = 'already_settled';
    public const MISMATCH = 'mismatch';
    public const IGNORED  = 'ignored';

    /**
     * @param array{source:string,amount_paise:?int,currency?:?string,utr?:?string,method?:?string,admin?:array{id:int,username:string,email:string},note?:string} $evidence
     * @return array{result:string,order_id:?int,payment_id:int,route:?string,message:string}
     */
    public static function settle(int $paymentId, array $evidence): array
    {
        $source = (string) $evidence['source'];
        $out = Db::tx(static function () use ($paymentId, $evidence, $source): array {
            $p = Db::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if ($p === null) {
                return self::out(self::IGNORED, null, $paymentId, null, 'Payment not found.');
            }
            $orderId = (int) $p['order_id'];
            if (in_array($p['status'], Status::PAY_RECEIVED, true)) {
                // A DIFFERENT bank reference for an already-paid order means the customer paid twice: leave a note so an admin can refund it.
                $again = isset($evidence['utr']) ? trim((string) $evidence['utr']) : '';
                if ($again !== '' && $source !== 'admin' && $p['provider_txn_id'] !== null && $again !== (string) $p['provider_txn_id']) {
                    $status = (string) Db::val('SELECT status FROM orders WHERE id = ?', [$orderId], '');
                    Orders::history($orderId, $status, $status, 'system', null, 'Duplicate payment received (bank ref ' . mb_substr($again, 0, 40) . ') for an order that was already paid. Refund the duplicate.');
                    Log::warning('Duplicate payment for an already-paid order', ['payment' => $paymentId, 'utr' => $again]);
                    Risk::record('duplicate_payment', [
                        'payment_id' => $paymentId, 'order_id' => $orderId, 'gateway_id' => $p['gateway_id'], 'key' => 'p' . $paymentId . ':' . substr(sha1($again), 0, 12),
                        'details' => ['kind' => 'second_reference', 'new_reference' => $again, 'first_reference' => (string) $p['provider_txn_id'], 'paid_via' => $source],
                    ]);
                }
                return self::out(self::ALREADY, $orderId, $paymentId, null, 'Payment was already confirmed.');
            }

            $expected = (int) $p['amount_paise'];
            $reported = $evidence['amount_paise'] ?? null;
            $now = Clock::now();

            if ($source !== 'admin') {
                if ($p['status'] === Status::PAY_MISMATCH) {
                    return self::out(self::MISMATCH, $orderId, $paymentId, null, 'Payment is flagged for manual review.');
                }
                // The shop is INR-only. A gateway that says the payment is in another currency has not paid ₹ — a human decides.
                $currency = isset($evidence['currency']) ? strtoupper(trim((string) $evidence['currency'])) : '';
                if ($currency !== '' && $currency !== 'INR') {
                    return self::flag($p, 'currency_mismatch', 'The gateway reported the payment in ' . mb_substr($currency, 0, 8) . ', not INR, so the amount cannot be verified automatically.', null, ['gateway_currency' => mb_substr($currency, 0, 8)]);
                }
                if ($reported === null) {
                    return self::flag($p, 'missing_amount', 'The gateway did not report the paid amount, so it cannot be verified automatically.');
                }
                if ($reported !== $expected) {
                    return self::flag($p, 'amount_mismatch', 'Amount mismatch: expected ' . Money::inr($expected, true) . ' but the gateway reported ' . Money::inr($reported, true) . '.', $reported);
                }
            }

            $utr = isset($evidence['utr']) && $evidence['utr'] !== '' ? mb_substr((string) $evidence['utr'], 0, 100) : null;
            if ($utr !== null && $p['gateway_id'] !== null) {
                $dup = Db::val('SELECT id FROM payments WHERE gateway_id = ? AND provider_txn_id = ? AND id <> ? LIMIT 1', [$p['gateway_id'], $utr, $paymentId]);
                if ($dup !== null) {
                    return self::flag($p, 'duplicate_reference', 'The bank reference ' . $utr . ' was already used by another payment.', null, ['reference' => $utr, 'other_payment_id' => (int) $dup]);
                }
            }

            $expiresAt = (int) Clock::fromSql($p['expires_at']);
            $late = $now > $expiresAt + 120;
            $day = Tz::today();
            Db::update('payments', [
                'status' => Status::PAY_SUCCESS, 'paid_at' => Clock::sql($now), 'paid_day' => $day,
                'paid_amount_paise' => $reported ?? $expected, 'provider_txn_id' => $utr,
                'gateway_method' => isset($evidence['method']) ? mb_substr((string) $evidence['method'], 0, 24) : null,
                'verified_via' => $source, 'late' => $late ? 1 : 0, 'failure_reason' => null, 'updated_at' => Clock::sql($now),
            ], 'id = ?', [$paymentId]);

            $o = Db::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($o === null) {
                return self::out(self::IGNORED, $orderId, $paymentId, null, 'Order not found.');
            }
            if ($late && Risk::thresholds()['flag_late_payments']) {
                Risk::record('late_payment', [
                    'payment_id' => $paymentId, 'order_id' => $orderId, 'gateway_id' => $p['gateway_id'],
                    'details' => ['seconds_after_expiry' => $now - $expiresAt, 'confirmed_via' => $source, 'order_status_before' => (string) $o['status']],
                ]);
            }

            if (!in_array($o['status'], [Status::PENDING_PAYMENT, Status::EXPIRED, Status::FAILED], true)) {
                // Money arrived for an order that was already cancelled/refunded/etc: record it, let a human decide.
                Orders::history($orderId, (string) $o['status'], (string) $o['status'], 'system', null,
                    'Payment confirmed (' . Money::inr($expected, true) . ') but the order was already “' . Status::orderLabel((string) $o['status']) . '”. Needs review.');
                Log::warning('Payment settled for an order that was not payable', ['order' => $o['public_id'], 'status' => $o['status']]);
                // an order that was already paid by another payment: the customer paid twice; anything else (cancelled, refunded …) is a mapping a person must look at
                Risk::record($o['paid_at'] !== null ? 'duplicate_payment' : 'mapping_inconsistent', [
                    'payment_id' => $paymentId, 'order_id' => $orderId, 'gateway_id' => $p['gateway_id'], 'key' => 'p' . $paymentId . ':order_not_payable',
                    'details' => ['kind' => 'order_not_payable', 'order_status' => (string) $o['status'], 'paid_via' => $source],
                ]);
                return self::out(self::SETTLED, $orderId, $paymentId, null, 'Payment recorded; the order needs review.');
            }

            $note = 'Payment confirmed via ' . $source . ($utr ? ' (ref ' . $utr . ')' : '') . ($late ? ' — received after the payment session ended' : '');
            Orders::transition($orderId, Status::PAID, [
                'actor' => $source === 'admin' ? 'admin' : ($source === 'webhook' ? 'webhook' : 'system'),
                'actor_id' => $evidence['admin']['id'] ?? null, 'actor_label' => $evidence['admin']['username'] ?? null,
                'note' => $note,
                'set' => ['paid_at' => Clock::sql($now), 'paid_day' => $day, 'payment_status' => 'success', 'late_payment' => $late ? 1 : 0, 'last_error' => null],
            ]);

            $route = $o['mode'] === Status::MANUAL ? 'MANUAL' : 'AUTO';
            if ($route === 'MANUAL') {
                Db::exec(
                    "INSERT IGNORE INTO manual_orders (order_id, state, created_at, updated_at) VALUES (?, 'pending', ?, ?)",
                    [$orderId, Clock::sql($now), Clock::sql($now)]
                );
                Orders::transition($orderId, Status::MANUAL_PENDING, ['actor' => 'system', 'note' => 'Queued for manual processing']);
            }

            if ($o['user_id'] !== null) {
                Db::exec('UPDATE users SET paid_orders_count = paid_orders_count + 1, total_spent_paise = total_spent_paise + ?, updated_at = ? WHERE id = ?',
                    [$expected, Clock::sql($now), $o['user_id']]);
            }
            $r = self::out(self::SETTLED, $orderId, $paymentId, $route, 'Payment confirmed.');
            $r['visitor_id'] = $o['visitor_id'] !== null ? (int) $o['visitor_id'] : null;
            $r['session_id'] = $o['visitor_session_id'] !== null ? (int) $o['visitor_session_id'] : null;
            $r['day'] = $day;
            return $r;
        });

        // Side effects that must not run inside the transaction.
        Risk::flush();
        if ($out['result'] === self::SETTLED && isset($out['day'])) {
            Tracking::onPaymentSucceeded($out['visitor_id'] ?? null, $out['session_id'] ?? null);
            Analytics::markDirty((string) $out['day']);
            if ($source === 'admin' && isset($evidence['admin'])) {
                Audit::log('payment.confirmed_manually', 'payment', $paymentId, ['note' => $evidence['note'] ?? ''], null, $evidence['admin']);
            }
        }
        return $out;
    }

    /**
     * Mark the payment for human review instead of fulfilling it, and record WHY as a payment flag (the flag is part of the same transaction).
     *
     * @param array<string,scalar> $facts
     */
    private static function flag(array $p, string $type, string $reason, ?int $reportedPaise = null, array $facts = []): array
    {
        Db::update('payments', [
            'status' => Status::PAY_MISMATCH, 'failure_reason' => mb_substr($reason, 0, 255),
            'paid_amount_paise' => $reportedPaise, 'updated_at' => Clock::sql(),
        ], 'id = ?', [$p['id']]);
        Orders::history((int) $p['order_id'], null, 'PENDING_PAYMENT', 'system', null, 'Payment flagged for review: ' . $reason);
        Log::warning('Payment flagged', ['payment' => $p['id'], 'type' => $type, 'reason' => $reason]);
        Risk::record($type, [
            'payment_id' => (int) $p['id'], 'order_id' => (int) $p['order_id'], 'gateway_id' => $p['gateway_id'] !== null ? (int) $p['gateway_id'] : null,
            'details' => $facts + ['expected_paise' => (int) $p['amount_paise']] + ($reportedPaise !== null ? ['reported_paise' => $reportedPaise] : []),
        ]);
        return self::out(self::MISMATCH, (int) $p['order_id'], (int) $p['id'], null, $reason);
    }

    /** @return array{result:string,order_id:?int,payment_id:int,route:?string,message:string} */
    private static function out(string $result, ?int $orderId, int $paymentId, ?string $route, string $message): array
    {
        return ['result' => $result, 'order_id' => $orderId, 'payment_id' => $paymentId, 'route' => $route, 'message' => $message];
    }
}
