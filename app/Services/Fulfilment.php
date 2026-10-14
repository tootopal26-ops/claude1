<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Request;
use App\Smm\SmmResponse;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Status;

/**
 * Delivery of PAID orders.
 *
 *   MANUAL services → waiting in the manual queue for an admin (approve / approve-all).
 *   AUTO services   → submitted to the SMM provider, then tracked until delivered.
 *
 * Double-order protection for AUTO: an order is first CLAIMED by moving
 * PAID → PROCESSING inside a row-locked transaction. Only the process that wins
 * that claim calls the provider, and the provider order id is stored the moment
 * it is known. If a call ends ambiguously (timeout / garbled reply) the order is
 * parked as FAILED for a human instead of being retried automatically.
 */
final class Fulfilment
{
    private const MAX_AUTO_ATTEMPTS = 5;
    private const BACKOFF = [60, 300, 1200, 3600];   // seconds before retry #2..#5

    // ═════════════════════════ AUTO: submit to provider ═════════════════════

    /**
     * @param array{id:int,username:string,email:string}|null $admin set for a manual retry
     * @return array{status:string,message?:string}
     */
    public static function submit(int $orderId, ?array $admin = null, bool $retry = false): array
    {
        $claimed = Orders::transition($orderId, Status::PROCESSING, [
            'actor' => $admin ? 'admin' : 'system', 'actor_id' => $admin['id'] ?? null, 'actor_label' => $admin['username'] ?? null,
            'note' => $retry ? 'Submitting to provider (manual retry)' : 'Submitting to provider',
            'expect' => $retry ? [Status::PAID, Status::FAILED] : [Status::PAID], 'soft' => true,
        ]);
        if (!$claimed) {
            return ['status' => 'skipped', 'message' => 'The order is not waiting for submission (already being processed or finished).'];
        }
        Db::exec('UPDATE orders SET provider_attempts = provider_attempts + 1, next_attempt_at = NULL WHERE id = ?', [$orderId]);
        $o = Orders::byId($orderId);

        if ($o['mode'] !== Status::AUTO) {
            return self::fail($o, 'This order is not an AUTO order.', 'misconfigured', $admin);
        }
        if ($retry) {
            // Re-read the service so a fixed SMM id / cost applies to the retry. A provider order that
            // was reported failed/cancelled is replaced; one that is still live must never be duplicated.
            if ($o['provider_order_id'] !== null && !in_array(strtolower((string) $o['provider_status']), ['fail', 'failed', 'canceled', 'cancelled', 'error'], true)) {
                return self::fail($o, 'This order already has a live provider order (' . $o['provider_order_id'] . '). Sync its status instead of resubmitting.', 'ambiguous', $admin);
            }
            $svc = Db::one('SELECT smm_provider_id, smm_service_id, smm_cost_paise FROM services WHERE id = ?', [$o['service_id']]);
            if ($svc !== null) {
                Db::update('orders', [
                    'smm_provider_id' => $svc['smm_provider_id'], 'smm_service_id' => $svc['smm_service_id'], 'estimated_cost_paise' => (int) $svc['smm_cost_paise'],
                    'provider_order_id' => null, 'provider_status' => null, 'provider_cost_paise' => 0, 'provider_cost_final' => 0,
                ], 'id = ?', [$orderId]);
                $o = Orders::byId($orderId);
            }
        }

        $provider = $o['smm_provider_id'] ? SmmProviders::row((int) $o['smm_provider_id']) : SmmProviders::defaultRow();
        if ($provider === null || !(int) $provider['is_active'] || trim((string) $o['smm_service_id']) === '') {
            return self::fail($o, 'This AUTO service has no active SMM provider or SMM service ID. Fix it in Admin → Services, then retry — or move the order to the manual queue.', 'misconfigured', $admin);
        }

        try {
            $res = SmmProviders::client($provider)->add((string) $o['smm_service_id'], (string) $o['target_url'], (int) $o['quantity']);
        } catch (\Throwable $e) {
            // The order is already claimed: whatever went wrong (a PHP error, a DNS hiccup…), never leave it half-way in PROCESSING.
            Log::exception($e, 'Provider call crashed', ['order' => $o['public_id']]);
            return self::fail($o, 'The order could not be sent to the provider because of an internal error (details are in the server log). Check the provider panel for this link before retrying — or move the order to the manual queue.', 'ambiguous', $admin);
        }
        $providerOrderId = $res->ok() && isset($res->data['order']) && is_scalar($res->data['order']) ? (string) $res->data['order'] : null;
        self::logTransaction($o, $provider, 'add', [
            'service' => $o['smm_service_id'], 'link' => $o['target_url'], 'quantity' => (int) $o['quantity'],
        ], $res, $providerOrderId);

        if ($providerOrderId !== null) {
            try {
                Orders::transition($orderId, Status::SUBMITTED, [
                    'actor' => 'provider', 'note' => 'Provider accepted the order (id ' . $providerOrderId . ')',
                    'set' => [
                        'provider_order_id' => mb_substr($providerOrderId, 0, 64), 'provider_status' => 'Pending', 'smm_provider_id' => $provider['id'],
                        'provider_cost_paise' => (int) $o['estimated_cost_paise'], 'provider_cost_final' => 0,
                        'submitted_at' => Clock::sql(), 'last_error' => null, 'failure_kind' => null, 'next_attempt_at' => null,
                    ],
                ]);
            } catch (\Throwable $e) {
                // The provider HAS the order but the database would not take the note (lock timeout, disconnect…). The order is
                // picked up by releaseStuck() and handed to a human; this log line says exactly which provider order to match.
                Log::error('Provider accepted the order but it could not be recorded', ['order' => $o['public_id'], 'provider_order_id' => $providerOrderId, 'provider_id' => $provider['id']]);
                throw $e;
            }
            return ['status' => 'submitted'];
        }

        if ($res->ok()) {
            // Answered 200 but without an order id: we cannot know what happened.
            return self::fail($o, 'The provider answered without an order id. Check the provider panel before retrying.', 'ambiguous', $admin);
        }
        return match ($res->kind) {
            SmmResponse::REJECTED => self::fail($o, 'Provider rejected the order: ' . $res->error, 'rejected', $admin),
            SmmResponse::UNREACHABLE => self::retryLater($o, $res->error, $admin),
            default => self::fail($o, 'Unclear provider response (' . $res->error . '). The order may or may not exist at the provider — verify there before retrying.', 'ambiguous', $admin),
        };
    }

    /** Provider unreachable: nothing was sent, so schedule an automatic retry with back-off. */
    private static function retryLater(array $o, string $error, ?array $admin): array
    {
        $attempts = (int) $o['provider_attempts'];
        if ($attempts >= self::MAX_AUTO_ATTEMPTS || $admin !== null) {
            return self::fail($o, 'Provider unreachable after ' . $attempts . ' attempt(s): ' . $error, 'unreachable', $admin);
        }
        $delay = self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)];
        Orders::transition((int) $o['id'], Status::PAID, [
            'actor' => 'system', 'note' => 'Provider unreachable (' . $error . '). Will retry in ' . intdiv($delay, 60) . ' min.',
            'set' => ['next_attempt_at' => Clock::sql(Clock::now() + $delay), 'last_error' => mb_substr($error, 0, 500), 'failure_kind' => 'unreachable'],
        ]);
        return ['status' => 'retry_scheduled', 'message' => $error];
    }

    private static function fail(array $o, string $message, string $kind, ?array $admin): array
    {
        Orders::transition((int) $o['id'], Status::FAILED, [
            'actor' => $admin ? 'admin' : 'system', 'actor_id' => $admin['id'] ?? null, 'actor_label' => $admin['username'] ?? null,
            'note' => $message, 'set' => ['last_error' => mb_substr($message, 0, 500), 'failure_kind' => $kind, 'next_attempt_at' => null],
        ]);
        Log::warning('AUTO order could not be submitted', ['order' => $o['public_id'], 'kind' => $kind, 'message' => $message]);
        return ['status' => 'failed', 'message' => $message];
    }

    /** @param array<string,mixed> $request */
    private static function logTransaction(array $o, array $provider, string $action, array $request, SmmResponse $res, ?string $providerOrderId, ?int $costPaise = null): void
    {
        try {
            Db::insert('smm_transactions', [
                'order_id' => $o['id'], 'provider_id' => $provider['id'], 'action' => $action,
                'request_json' => json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),   // never contains the API key
                'response_body' => $res->rawBody !== '' ? mb_substr($res->rawBody, 0, 1000) : null,
                'http_status' => $res->httpStatus, 'ok' => $res->ok() ? 1 : 0, 'error' => $res->error !== '' ? mb_substr($res->error, 0, 500) : null,
                'provider_order_id' => $providerOrderId, 'cost_paise' => $costPaise, 'duration_ms' => $res->durationMs, 'created_at' => Clock::sql(),
            ]);
        } catch (\Throwable $e) {
            Log::exception($e, 'Could not record SMM transaction');
        }
    }

    // ═════════════════════════ AUTO: follow the provider ════════════════════

    /**
     * Pull the provider's status for one order and apply it (status, remains,
     * and the ACTUAL charge, which replaces the estimated cost).
     *
     * @return array{ok:bool,message:string}
     */
    public static function sync(int $orderId): array
    {
        $o = Orders::byId($orderId) ?? throw new HttpException(404, 'Order not found.');
        if ($o['provider_order_id'] === null || $o['smm_provider_id'] === null) {
            return ['ok' => false, 'message' => 'This order has no provider order to sync.'];
        }
        $provider = SmmProviders::row((int) $o['smm_provider_id']);
        if ($provider === null) {
            return ['ok' => false, 'message' => 'The provider record no longer exists.'];
        }
        $res = SmmProviders::client($provider)->status((string) $o['provider_order_id']);
        self::logTransaction($o, $provider, 'status', ['order' => $o['provider_order_id']], $res, (string) $o['provider_order_id']);
        Db::update('orders', ['last_sync_at' => Clock::sql()], 'id = ?', [$orderId]);
        if (!$res->ok() || !is_array($res->data)) {
            return ['ok' => false, 'message' => $res->error !== '' ? $res->error : 'No usable answer from the provider.'];
        }

        $d = $res->data;
        $pStatus = isset($d['status']) && is_scalar($d['status']) ? mb_substr((string) $d['status'], 0, 32) : '';
        $internal = $pStatus !== '' ? Status::fromProviderStatus($pStatus) : null;

        $set = ['provider_status' => $pStatus !== '' ? $pStatus : $o['provider_status']];
        if (isset($d['start_count']) && is_numeric($d['start_count'])) {
            $set['start_count'] = (int) $d['start_count'];
        }
        if (isset($d['remains']) && is_numeric($d['remains'])) {
            $set['remains'] = (int) $d['remains'];
        }

        // Cost: the provider's real charge replaces the estimate. Cancelled/failed = provider refunded us.
        $terminal = in_array($internal, [Status::COMPLETED, Status::PARTIAL, Status::CANCELLED, Status::FAILED], true);
        if (in_array($internal, [Status::CANCELLED, Status::FAILED], true)) {
            $set['provider_cost_paise'] = 0;
            $set['provider_cost_final'] = 1;
        } elseif (isset($d['charge'])) {
            $micros = Money::parseDecimalMicros(is_scalar($d['charge']) ? $d['charge'] : null);
            $paise = $micros !== null ? Money::chargeToPaise($micros, (int) $provider['rate_to_inr_micros']) : null;
            if ($paise !== null && ($paise > 0 || $terminal)) {
                $set['provider_cost_paise'] = $paise;
                $set['provider_cost_final'] = $terminal ? 1 : 0;
            }
        }

        $changed = false;
        if ($internal !== null && $internal !== $o['status'] && in_array($o['status'], [Status::SUBMITTED, Status::IN_PROGRESS], true)) {
            $changed = Orders::transition($orderId, $internal, [
                'actor' => 'provider', 'note' => 'Provider status: ' . $pStatus, 'set' => $set, 'soft' => true,
                'meta' => ['remains' => $set['remains'] ?? null, 'start_count' => $set['start_count'] ?? null],
            ]);
        }
        if (!$changed) {
            Db::update('orders', $set, 'id = ?', [$orderId]);
            if ($o['paid_day'] !== null) {
                Analytics::markDirty((string) $o['paid_day']);
            }
        }
        return ['ok' => true, 'message' => 'Provider status: ' . ($pStatus ?: 'unknown')];
    }

    /**
     * Cron: sync a batch of live provider orders (oldest-synced first).
     *
     * @return array{synced:int,errors:int}
     */
    public static function syncDue(int $limit = 30): array
    {
        $rows = Db::all(
            "SELECT id FROM orders WHERE status IN ('SUBMITTED','IN_PROGRESS') AND provider_order_id IS NOT NULL AND (last_sync_at IS NULL OR last_sync_at <= ?) ORDER BY last_sync_at IS NULL DESC, last_sync_at LIMIT ?",
            [Clock::sql(Clock::now() - 120), $limit]
        );
        $n = ['synced' => 0, 'errors' => 0];
        foreach ($rows as $r) {
            try {
                $res = self::sync((int) $r['id']);
                $res['ok'] ? $n['synced']++ : $n['errors']++;
            } catch (\Throwable $e) {
                Log::exception($e, 'Order sync failed', ['order_id' => $r['id']]);
                $n['errors']++;
            }
        }
        return $n;
    }

    /**
     * Cron watchdog. A process that dies right after claiming an order (the host killed it, the server restarted…) leaves it in
     * PROCESSING for ever. The provider may or may not have received it, so it is never resubmitted automatically: it is handed to
     * a human like every other unclear outcome. (A live submission is bounded by the 30 s provider timeout, so 10 min is far beyond it.)
     *
     * @return int number of orders handed over
     */
    public static function releaseStuck(int $olderThanSeconds = 600, int $limit = 20): int
    {
        $ids = Db::col(
            "SELECT id FROM orders WHERE status = 'PROCESSING' AND provider_order_id IS NULL AND updated_at <= ? ORDER BY id LIMIT ?",
            [Clock::sql(Clock::now() - $olderThanSeconds), $limit]
        );
        $n = 0;
        foreach ($ids as $id) {
            $message = 'The submission was interrupted before the provider\'s answer was recorded. The provider may already have this order — check its panel (and the transaction log) for this link before retrying.';
            $moved = Orders::transition((int) $id, Status::FAILED, [
                'actor' => 'system', 'note' => $message, 'expect' => [Status::PROCESSING], 'soft' => true,
                'set' => ['last_error' => $message, 'failure_kind' => 'ambiguous', 'next_attempt_at' => null],
            ]);
            if ($moved) {
                Log::warning('Order was stuck in PROCESSING and has been handed to an admin', ['order_id' => $id]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Cron safety net: AUTO orders that are PAID but not yet sent (the request
     * that settled them died, or a scheduled retry is due).
     *
     * @return array{attempted:int,released:int}
     */
    public static function submitDue(int $limit = 20): array
    {
        $released = self::releaseStuck();
        $rows = Db::all(
            "SELECT id FROM orders WHERE status = 'PAID' AND mode = 'AUTO' AND provider_order_id IS NULL AND paid_at <= ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT ?",
            [Clock::sql(Clock::now() - 20), Clock::sql(), $limit]
        );
        foreach ($rows as $r) {
            try {
                self::submit((int) $r['id']);
            } catch (\Throwable $e) {
                Log::exception($e, 'Scheduled submission failed', ['order_id' => $r['id']]);
            }
        }
        return ['attempted' => count($rows), 'released' => $released];
    }

    // ═════════════════════════ MANUAL queue ═════════════════════════════════

    /** Send a stuck AUTO order to the manual queue (the admin will fulfil it by hand). */
    public static function moveToManual(int $orderId, array $admin, Request $req): void
    {
        $o = Orders::byId($orderId) ?? throw new HttpException(404, 'Order not found.');
        if ($o['paid_at'] === null) {
            throw new HttpException(409, 'Only paid orders can be processed manually.', 'not_paid');
        }
        if ($o['provider_order_id'] !== null && !in_array(strtolower((string) $o['provider_status']), ['fail', 'failed', 'canceled', 'cancelled', 'error'], true)) {
            throw new HttpException(409, 'This order is live at the provider. Sync it instead.', 'conflict');
        }
        Db::tx(static function () use ($orderId, $admin): void {
            Orders::transition($orderId, Status::MANUAL_PENDING, [
                'actor' => 'admin', 'actor_id' => $admin['id'], 'actor_label' => $admin['username'], 'note' => 'Moved to the manual queue',
                'set' => ['mode' => Status::MANUAL, 'estimated_cost_paise' => 0, 'provider_cost_paise' => 0, 'provider_cost_final' => 1, 'last_error' => null, 'failure_kind' => null, 'next_attempt_at' => null],
            ]);
            Db::exec("INSERT INTO manual_orders (order_id, state, created_at, updated_at) VALUES (?, 'pending', ?, ?) ON DUPLICATE KEY UPDATE state = 'pending', updated_at = VALUES(updated_at)",
                [$orderId, Clock::sql(), Clock::sql()]);
        });
        Audit::log('order.action', 'order', $orderId, ['action' => 'move_to_manual'], $req, $admin);
    }

    /** Approve one manual order. */
    public static function approve(int $orderId, array $admin, Request $req, string $note = ''): void
    {
        Db::tx(static function () use ($orderId, $admin, $note): void {
            $o = Db::one('SELECT id, status, mode, payment_status, paid_at FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($o === null) {
                throw new HttpException(404, 'Order not found.');
            }
            self::assertApprovable($o);
            Orders::transition($orderId, Status::MANUAL_APPROVED, [
                'actor' => 'admin', 'actor_id' => $admin['id'], 'actor_label' => $admin['username'], 'note' => $note !== '' ? 'Approved: ' . $note : 'Approved',
            ]);
            Db::exec("UPDATE manual_orders SET state = 'approved', approved_by = ?, approved_at = ?, admin_note = COALESCE(NULLIF(?, ''), admin_note), updated_at = ? WHERE order_id = ?",
                [$admin['id'], Clock::sql(), $note, Clock::sql(), $orderId]);
        });
        Audit::log('order.approved', 'order', $orderId, [], $req, $admin);
    }

    /** @param array<string,mixed> $o */
    private static function assertApprovable(array $o): void
    {
        if ($o['status'] !== Status::MANUAL_PENDING || $o['mode'] !== Status::MANUAL || $o['payment_status'] !== 'success' || $o['paid_at'] === null) {
            throw new HttpException(409, 'Only paid manual orders that are waiting for approval can be approved.', 'not_approvable');
        }
    }

    /**
     * APPROVE ALL — in ONE transaction, approve every ELIGIBLE order: paid,
     * MANUAL mode, currently MANUAL_PENDING. Unpaid, cancelled, refunded and
     * already-completed orders are never touched.
     *
     * @return array{approved:int}
     */
    public static function approveAll(array $admin, Request $req): array
    {
        $approved = Db::tx(static function () use ($admin): int {
            $rows = Db::all(
                "SELECT o.id FROM orders o JOIN manual_orders m ON m.order_id = o.id "
                . "WHERE o.status = 'MANUAL_PENDING' AND o.mode = 'MANUAL' AND o.payment_status = 'success' AND o.paid_at IS NOT NULL AND m.state = 'pending' "
                . 'ORDER BY o.id FOR UPDATE'
            );
            foreach ($rows as $r) {
                Orders::transition((int) $r['id'], Status::MANUAL_APPROVED, [
                    'actor' => 'admin', 'actor_id' => $admin['id'], 'actor_label' => $admin['username'], 'note' => 'Approved (Approve All)', 'soft' => true,
                ]);
                Db::exec("UPDATE manual_orders SET state = 'approved', approved_by = ?, approved_at = ?, updated_at = ? WHERE order_id = ?",
                    [$admin['id'], Clock::sql(), Clock::sql(), $r['id']]);
            }
            return count($rows);
        });
        Audit::log('order.approve_all', 'order', null, ['approved' => $approved], $req, $admin);
        return ['approved' => $approved];
    }

    /** Count of orders Approve All would approve right now (shown in the confirmation dialog). */
    public static function approvableCount(): int
    {
        return (int) Db::val(
            "SELECT COUNT(*) FROM orders o JOIN manual_orders m ON m.order_id = o.id WHERE o.status = 'MANUAL_PENDING' AND o.mode = 'MANUAL' AND o.payment_status = 'success' AND o.paid_at IS NOT NULL AND m.state = 'pending'"
        );
    }

    // ═════════════════════════ completion / cancel / refund ═════════════════

    /** Mark an approved/partial order as delivered. */
    public static function complete(int $orderId, array $admin, Request $req): void
    {
        Orders::transition($orderId, Status::COMPLETED, ['actor' => 'admin', 'actor_id' => $admin['id'], 'actor_label' => $admin['username'], 'note' => 'Marked completed']);
        Audit::log('order.action', 'order', $orderId, ['action' => 'complete'], $req, $admin);
    }

    public static function cancel(int $orderId, array $admin, Request $req, string $note): void
    {
        Db::tx(static function () use ($orderId, $admin, $note): void {
            Orders::transition($orderId, Status::CANCELLED, ['actor' => 'admin', 'actor_id' => $admin['id'], 'actor_label' => $admin['username'], 'note' => $note !== '' ? 'Cancelled: ' . $note : 'Cancelled']);
            Db::exec("UPDATE manual_orders SET state = 'rejected', updated_at = ? WHERE order_id = ? AND state = 'pending'", [Clock::sql(), $orderId]);
        });
        Audit::log('order.action', 'order', $orderId, ['action' => 'cancel'], $req, $admin);
    }

    /**
     * Record a refund. Alpha UPI has no refund API, so the admin sends the money
     * back from their UPI app and records the reference here; the app then
     * reverses the revenue. (A gateway that supports refunds would be called first.)
     *
     * @return array{refunded_paise:int,full:bool}
     */
    public static function refund(int $orderId, int $amountPaise, string $reference, array $admin, Request $req): array
    {
        $reference = trim($reference);
        if ($amountPaise < 1) {
            throw new HttpException(422, 'Enter a refund amount greater than zero.', 'invalid', ['fields' => ['amount' => 'Enter a refund amount greater than zero.']]);
        }
        if ($reference === '' || mb_strlen($reference) > 190) {
            throw new HttpException(422, 'Enter the UPI/bank reference of the refund you sent.', 'invalid', ['fields' => ['reference' => 'Required (up to 190 characters).']]);
        }

        $res = Db::tx(static function () use ($orderId, $amountPaise, $reference, $admin): array {
            $o = Db::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($o === null) {
                throw new HttpException(404, 'Order not found.');
            }
            $p = Db::one("SELECT * FROM payments WHERE order_id = ? AND paid_at IS NOT NULL ORDER BY id DESC LIMIT 1 FOR UPDATE", [$orderId]);
            if ($o['paid_at'] === null || $p === null || $o['status'] === Status::REFUNDED) {
                throw new HttpException(409, 'Only paid, not-yet-refunded orders can be refunded.', 'not_refundable');
            }
            $remaining = (int) $o['amount_paise'] - (int) $o['refunded_paise'];
            if ($amountPaise > $remaining) {
                throw new HttpException(422, 'You can refund at most ' . Money::inr($remaining, true) . ' for this order.', 'invalid', ['fields' => ['amount' => 'At most ' . Money::inr($remaining, true) . '.']]);
            }
            $newRefunded = (int) $o['refunded_paise'] + $amountPaise;
            $full = $newRefunded >= (int) $o['amount_paise'];

            Db::update('payments', [
                'refunded_paise' => $newRefunded, 'refund_reference' => mb_substr($reference, 0, 190),
                'status' => $full ? Status::PAY_REFUNDED : Status::PAY_PART_REFUNDED, 'updated_at' => Clock::sql(),
            ], 'id = ?', [$p['id']]);
            Db::update('orders', ['refunded_paise' => $newRefunded, 'payment_status' => $full ? 'refunded' : 'partially_refunded'], 'id = ?', [$orderId]);

            $note = ($full ? 'Fully refunded ' : 'Partially refunded ') . Money::inr($amountPaise, true) . ' (ref ' . $reference . ')';
            if ($full) {
                Orders::transition($orderId, Status::REFUNDED, ['actor' => 'admin', 'actor_id' => $admin['id'], 'actor_label' => $admin['username'], 'note' => $note]);
                Db::exec("UPDATE manual_orders SET state = 'rejected', updated_at = ? WHERE order_id = ? AND state = 'pending'", [Clock::sql(), $orderId]);
            } else {
                Orders::history($orderId, (string) $o['status'], (string) $o['status'], 'admin', $admin['id'], $note, null, $admin['username']);
                if ($o['paid_day'] !== null) {
                    Analytics::markDirty((string) $o['paid_day']);
                }
            }
            return ['refunded_paise' => $newRefunded, 'full' => $full, 'paid_day' => $o['paid_day']];
        });
        if ($res['paid_day'] !== null) {
            Analytics::markDirty((string) $res['paid_day']);
        }
        Audit::log('order.refunded', 'order', $orderId, ['amount' => Money::plain($amountPaise), 'full' => $res['full']], $req, $admin);
        return ['refunded_paise' => $res['refunded_paise'], 'full' => $res['full']];
    }
}
