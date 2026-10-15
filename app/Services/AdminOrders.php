<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Profit;
use App\Support\Status;
use App\Support\Targets;
use App\Support\Tz;

/** Order listing, detail and actions for the admin panel. */
final class AdminOrders
{
    private const FILTERS = [
        'pending'    => "o.status = 'PENDING_PAYMENT'",
        'paid'       => 'o.paid_at IS NOT NULL',
        'processing' => "o.status IN ('PAID','PROCESSING','SUBMITTED','IN_PROGRESS')",
        'completed'  => "o.status IN ('COMPLETED','MANUAL_APPROVED')",
        'manual'     => "o.mode = 'MANUAL'",
        'auto'       => "o.mode = 'AUTO'",
        'failed'     => "o.status = 'FAILED'",
        'refunded'   => "o.status = 'REFUNDED'",
    ];

    /**
     * @param array<string,mixed> $q filter, status, mode, platform, q, from, to, page, per
     * @return array<string,mixed>
     */
    public static function list(array $q): array
    {
        $where = ['1 = 1'];
        $params = [];
        $filter = (string) ($q['filter'] ?? 'all');
        if (isset(self::FILTERS[$filter])) {
            $where[] = self::FILTERS[$filter];
        } elseif ($filter !== 'all' && $filter !== '') {
            throw new HttpException(422, 'Unknown filter.', 'invalid');
        }
        $status = (string) ($q['status'] ?? '');
        if ($status !== '') {
            if (!in_array($status, Status::ORDER_ALL, true)) {
                throw new HttpException(422, 'Unknown status.', 'invalid');
            }
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if (in_array($q['mode'] ?? '', ['AUTO', 'MANUAL'], true)) {
            $where[] = 'o.mode = ?';
            $params[] = $q['mode'];
        }
        if (($q['platform'] ?? '') !== '') {
            $where[] = 'o.platform_slug = ?';
            $params[] = (string) $q['platform'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = (string) ($q[$k] ?? '');
            if ($d !== '') {
                if (!Tz::isDay($d)) {
                    throw new HttpException(422, 'Choose valid dates.', 'invalid');
                }
                $where[] = "o.created_day {$op} ?";
                $params[] = $d;
            }
        }
        $search = trim((string) ($q['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes(mb_substr($search, 0, 100), '%_\\') . '%';
            $where[] = '(o.public_id LIKE ? OR o.target LIKE ? OR o.service_title LIKE ? OR o.provider_order_id = ? OR p.gateway_order_id = ? OR p.provider_txn_id = ?)';
            array_push($params, $like, $like, $like, $search, $search, $search);
        }

        $per = max(10, min(100, (int) ($q['per'] ?? 25)));
        $page = max(1, (int) ($q['page'] ?? 1));
        $from = 'FROM orders o LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id) LEFT JOIN smm_providers pr ON pr.id = o.smm_provider_id';
        $whereSql = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) {$from} WHERE {$whereSql}", $params);
        $rows = Db::all(
            "SELECT o.*, p.gateway_order_id, p.provider_txn_id, p.status AS pay_row_status, pr.name AS provider_name {$from} WHERE {$whereSql} ORDER BY o.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$per, ($page - 1) * $per])
        );

        $counts = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM orders GROUP BY status') as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $sum = static fn(array $keys): int => array_sum(array_map(static fn(string $k): int => $counts[$k] ?? 0, $keys));
        $chips = [
            'all' => array_sum($counts), 'pending' => $sum(['PENDING_PAYMENT']), 'processing' => $sum(['PAID', 'PROCESSING', 'SUBMITTED', 'IN_PROGRESS']),
            'completed' => $sum(['COMPLETED', 'MANUAL_APPROVED']), 'failed' => $sum(['FAILED']), 'refunded' => $sum(['REFUNDED']),
            'paid' => (int) Db::val('SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL'),
            'manual' => (int) Db::val("SELECT COUNT(*) FROM orders WHERE mode = 'MANUAL'"), 'auto' => (int) Db::val("SELECT COUNT(*) FROM orders WHERE mode = 'AUTO'"),
        ];

        return [
            'rows' => array_map([self::class, 'row'], $rows),
            'page' => $page, 'per' => $per, 'total' => $total, 'pages' => max(1, (int) ceil($total / $per)),
            'chips' => $chips, 'approvable' => Fulfilment::approvableCount(),
        ];
    }

    /** @return array<string,mixed> */
    private static function row(array $o): array
    {
        $paid = $o['paid_at'] !== null;
        $profit = $paid ? Profit::forOrder($o) : null;
        return [
            'id' => (int) $o['id'], 'public_id' => $o['public_id'], 'platform' => $o['platform_slug'], 'service' => $o['service_title'], 'target' => $o['target'], 'target_url' => $o['target_url'],
            'quantity' => (int) $o['quantity'], 'amount_paise' => (int) $o['amount_paise'], 'refunded_paise' => (int) $o['refunded_paise'],
            'payment_status' => $o['payment_status'], 'mode' => $o['mode'], 'provider' => $o['provider_name'], 'provider_order_id' => $o['provider_order_id'],
            'cost_paise' => $paid ? ($profit['cost']) : null, 'cost_final' => (bool) $o['provider_cost_final'], 'profit_paise' => $profit['gross'] ?? null,
            'status' => $o['status'], 'status_label' => Status::orderLabel((string) $o['status']), 'failure_kind' => $o['failure_kind'], 'late' => (bool) $o['late_payment'],
            'last_error' => $o['last_error'] !== null ? mb_substr((string) $o['last_error'], 0, 160) : null,
            'created_at' => Clock::iso($o['created_at']), 'paid_at' => Clock::iso($o['paid_at']), 'actions' => self::allowed($o),
        ];
    }

    /** Which admin actions make sense for this order right now. @return list<string> */
    public static function allowed(array $o): array
    {
        $s = (string) $o['status'];
        $paid = $o['paid_at'] !== null;
        $a = [];
        if ($s === Status::MANUAL_PENDING && $o['mode'] === Status::MANUAL && $o['payment_status'] === 'success') {
            $a[] = 'approve';
        }
        if (in_array($s, [Status::MANUAL_APPROVED, Status::PARTIAL, Status::MANUAL_PENDING], true) && $paid) {
            $a[] = 'complete';
        }
        if ($o['mode'] === Status::AUTO && $paid && $s === Status::FAILED) {
            $a[] = 'retry_submit';
        }
        if ($paid && $o['mode'] === Status::AUTO && in_array($s, [Status::FAILED, Status::PAID, Status::PROCESSING], true) && $o['provider_order_id'] === null) {
            $a[] = 'move_to_manual';
        }
        if ($o['provider_order_id'] !== null && in_array($s, [Status::SUBMITTED, Status::IN_PROGRESS], true)) {
            $a[] = 'sync';
        }
        if (Status::canTransition($s, Status::CANCELLED)) {
            $a[] = 'cancel';
        }
        if ($paid && $s !== Status::REFUNDED && (int) $o['refunded_paise'] < (int) $o['amount_paise']) {
            $a[] = 'refund';
        }
        if (!$paid && in_array($s, [Status::PENDING_PAYMENT, Status::EXPIRED, Status::FAILED], true)) {
            $a[] = 'confirm_payment';
        }
        $a[] = 'note';
        return $a;
    }

    /** @return array<string,mixed> */
    public static function detail(int $id): array
    {
        $o = Db::one(
            'SELECT o.*, pr.name AS provider_name FROM orders o LEFT JOIN smm_providers pr ON pr.id = o.smm_provider_id WHERE o.id = ?',
            [$id]
        ) ?? throw new HttpException(404, 'Order not found.');
        $payments = Db::all('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC', [$id]);
        $paymentIds = array_column($payments, 'id');
        $webhooks = $paymentIds ? Db::all(
            'SELECT id, event_id, event_type, signature_valid, status, error, attempts, received_at, processed_at FROM webhook_events WHERE payment_id IN (' . Db::in($paymentIds) . ') ORDER BY id DESC LIMIT 20',
            $paymentIds
        ) : [];
        $history = Db::all('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id', [$id]);
        $smm = Db::all('SELECT * FROM smm_transactions WHERE order_id = ? ORDER BY id DESC LIMIT 25', [$id]);
        $manual = Db::one('SELECT m.*, a.username AS approver FROM manual_orders m LEFT JOIN admins a ON a.id = m.approved_by WHERE m.order_id = ?', [$id]);
        $paid = $o['paid_at'] !== null;
        $profit = $paid ? Profit::forOrder($o) : null;

        $pv = static fn(array $p): array => [
            'id' => (int) $p['id'], 'status' => $p['status'], 'provider' => $p['provider'], 'gateway_order_id' => $p['gateway_order_id'], 'utr' => $p['provider_txn_id'],
            'method' => $p['gateway_method'], 'amount_paise' => (int) $p['amount_paise'], 'paid_amount_paise' => $p['paid_amount_paise'] !== null ? (int) $p['paid_amount_paise'] : null,
            'refunded_paise' => (int) $p['refunded_paise'], 'refund_reference' => $p['refund_reference'], 'verified_via' => $p['verified_via'], 'late' => (bool) $p['late'],
            'failure_reason' => $p['failure_reason'], 'expires_at' => Clock::iso($p['expires_at']), 'paid_at' => Clock::iso($p['paid_at']), 'created_at' => Clock::iso($p['created_at']),
            'polls' => (int) $p['poll_count'], 'last_polled_at' => Clock::iso($p['last_polled_at']),
        ];

        return [
            'order' => self::row($o) + [
                'link_only' => Targets::orderHasAccountLink((string) $o['platform_slug'], (string) $o['service_type']),
                'target_label' => Targets::orderHasAccountLink((string) $o['platform_slug'], (string) $o['service_type']) ? Targets::label((string) $o['platform_slug'], true) : 'Target',
                'access_url' => absolute_url('order/' . $o['access_token']), 'service_code' => $o['service_code'], 'service_type' => $o['service_type'],
                'admin_note' => $o['admin_note'], 'start_count' => $o['start_count'] !== null ? (int) $o['start_count'] : null, 'remains' => $o['remains'] !== null ? (int) $o['remains'] : null,
                'attempts' => (int) $o['provider_attempts'], 'next_attempt_at' => Clock::iso($o['next_attempt_at']), 'provider_status' => $o['provider_status'],
                'estimated_cost_paise' => (int) $o['estimated_cost_paise'], 'smm_service_id' => $o['smm_service_id'], 'submitted_at' => Clock::iso($o['submitted_at']), 'completed_at' => Clock::iso($o['completed_at']),
            ],
            'money' => $profit === null ? null : $profit + ['amount' => (int) $o['amount_paise'], 'refunded' => (int) $o['refunded_paise'], 'mode' => $o['mode']],
            'payments' => array_map($pv, $payments),
            'webhooks' => array_map(static fn(array $w): array => [
                'id' => (int) $w['id'], 'event_id' => $w['event_id'], 'type' => $w['event_type'], 'signature_valid' => (bool) $w['signature_valid'], 'status' => $w['status'],
                'error' => $w['error'], 'attempts' => (int) $w['attempts'], 'received_at' => Clock::iso($w['received_at']),
            ], $webhooks),
            'history' => array_map(static fn(array $h): array => [
                'from' => $h['from_status'], 'to' => $h['to_status'], 'to_label' => Status::orderLabel((string) $h['to_status']), 'actor' => $h['actor_type'],
                'actor_label' => $h['actor_label'], 'note' => $h['note'], 'at' => Clock::iso($h['created_at']),
            ], $history),
            'smm' => array_map(static fn(array $t): array => [
                'id' => (int) $t['id'], 'action' => $t['action'], 'ok' => (bool) $t['ok'], 'http_status' => $t['http_status'] !== null ? (int) $t['http_status'] : null, 'error' => $t['error'],
                'request' => $t['request_json'], 'response' => $t['response_body'], 'provider_order_id' => $t['provider_order_id'], 'duration_ms' => $t['duration_ms'] !== null ? (int) $t['duration_ms'] : null,
                'at' => Clock::iso($t['created_at']),
            ], $smm),
            'manual' => $manual ? ['state' => $manual['state'], 'approved_at' => Clock::iso($manual['approved_at']), 'approved_by' => $manual['approver'], 'note' => $manual['admin_note']] : null,
        ];
    }

    /**
     * Run one admin action on an order.
     *
     * @param array<string,mixed> $in
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed> message + fresh detail
     */
    public static function act(int $id, array $in, Request $req, array $admin): array
    {
        $o = Orders::byId($id) ?? throw new HttpException(404, 'Order not found.');
        $action = (string) ($in['action'] ?? '');
        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 400);
        if (!in_array($action, self::allowed($o), true)) {
            throw new HttpException(409, 'That action is not available for this order right now.', 'not_allowed');
        }

        $message = 'Done.';
        switch ($action) {
            case 'approve':
                Fulfilment::approve($id, $admin, $req, $note);
                $message = 'Order approved.';
                break;
            case 'complete':
                Fulfilment::complete($id, $admin, $req);
                $message = 'Order marked as completed.';
                break;
            case 'cancel':
                Fulfilment::cancel($id, $admin, $req, $note);
                $message = 'Order cancelled.';
                break;
            case 'retry_submit':
                $r = Fulfilment::submit($id, $admin, true);
                $message = $r['status'] === 'submitted' ? 'Submitted to the provider.' : ($r['message'] ?? 'The retry did not succeed — see the order timeline.');
                break;
            case 'move_to_manual':
                Fulfilment::moveToManual($id, $admin, $req);
                $message = 'Moved to the manual queue.';
                break;
            case 'sync':
                $r = Fulfilment::sync($id);
                if (!$r['ok']) {
                    throw new HttpException(502, 'Could not sync: ' . $r['message'], 'provider_error');
                }
                $message = $r['message'];
                break;
            case 'refund':
                $amount = Money::parseRupees(is_scalar($in['amount'] ?? null) ? $in['amount'] : null);
                $r = Fulfilment::refund($id, $amount, (string) ($in['reference'] ?? ''), $admin, $req);
                $message = $r['full'] ? 'Order refunded in full.' : 'Partial refund recorded.';
                break;
            case 'confirm_payment':
                $payment = Db::one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$id]);
                $reference = trim((string) ($in['reference'] ?? ''));
                if ($payment === null || $reference === '') {
                    throw new HttpException(422, 'Enter the bank / UPI reference you verified the payment with.', 'invalid', ['fields' => ['reference' => 'Required.']]);
                }
                $s = Settlement::settle((int) $payment['id'], ['source' => 'admin', 'amount_paise' => (int) $payment['amount_paise'], 'utr' => $reference, 'admin' => $admin, 'note' => $note]);
                if ($s['result'] !== Settlement::SETTLED) {
                    throw new HttpException(409, $s['message'], 'not_settled');
                }
                if ($s['route'] === 'AUTO') {
                    Fulfilment::submit((int) $s['order_id']);
                }
                $message = 'Payment confirmed manually.';
                break;
            case 'note':
                Db::update('orders', ['admin_note' => $note !== '' ? $note : null, 'updated_at' => Clock::sql()], 'id = ?', [$id]);
                Audit::log('order.action', 'order', $id, ['action' => 'note'], $req, $admin);
                $message = 'Note saved.';
                break;
        }
        return ['message' => $message, 'detail' => self::detail($id)];
    }

    // ── payments page ──

    /** @return array<string,mixed> */
    public static function payments(array $q): array
    {
        $per = 25;
        $page = max(1, (int) ($q['page'] ?? 1));
        $where = '1 = 1';
        $params = [];
        $status = (string) ($q['status'] ?? '');
        if ($status !== '') {
            $where .= match ($status) {
                'success' => " AND p.paid_at IS NOT NULL", 'pending' => " AND p.status IN ('creating','pending')", 'failed' => " AND p.status IN ('failed','mismatch')",
                'expired' => " AND p.status = 'expired'", 'refunded' => " AND p.status IN ('refunded','partially_refunded')", 'mismatch' => " AND p.status = 'mismatch'",
                default => throw new HttpException(422, 'Unknown status.', 'invalid'),
            };
        }
        $search = trim((string) ($q['q'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (o.public_id LIKE ? OR p.gateway_order_id = ? OR p.provider_txn_id = ?)';
            array_push($params, '%' . addcslashes(mb_substr($search, 0, 60), '%_\\') . '%', $search, $search);
        }
        $total = (int) Db::val("SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE {$where}", $params);
        $rows = Db::all(
            "SELECT p.*, o.public_id, o.service_title, o.id AS oid FROM payments p JOIN orders o ON o.id = p.order_id WHERE {$where} ORDER BY p.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$per, ($page - 1) * $per])
        );
        $events = Db::all('SELECT id, provider, event_id, event_type, signature_valid, status, error, attempts, received_at FROM webhook_events ORDER BY id DESC LIMIT 15');
        return [
            'rows' => array_map(static fn(array $p): array => [
                'id' => (int) $p['id'], 'order_id' => (int) $p['oid'], 'order' => $p['public_id'], 'service' => $p['service_title'], 'status' => $p['status'],
                'amount_paise' => (int) $p['amount_paise'], 'refunded_paise' => (int) $p['refunded_paise'], 'utr' => $p['provider_txn_id'], 'gateway_order_id' => $p['gateway_order_id'],
                'via' => $p['verified_via'], 'late' => (bool) $p['late'], 'reason' => $p['failure_reason'], 'created_at' => Clock::iso($p['created_at']), 'paid_at' => Clock::iso($p['paid_at']),
            ], $rows),
            'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total,
            'webhooks' => array_map(static fn(array $w): array => [
                'id' => (int) $w['id'], 'event_id' => $w['event_id'], 'type' => $w['event_type'], 'signature_valid' => (bool) $w['signature_valid'], 'status' => $w['status'],
                'error' => $w['error'], 'attempts' => (int) $w['attempts'], 'at' => Clock::iso($w['received_at']),
            ], $events),
        ];
    }
}
