<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Tz;

/**
 * Profit Intelligence: what an order really cost, what the business spent, and how honest each profit figure is.
 *
 * THE DEFINITIONS (one place; the screens, the daily report and the tests all use these words — see docs/PROFIT-INTELLIGENCE.md)
 *
 *   collected          the amount of every order whose payment was VERIFIED (not pending, failed or expired), on the day it was verified
 *                      (Asia/Kolkata). An order that is paid late counts on the day the money was confirmed.
 *   refunds            what an admin recorded as returned to the customer (orders.refunded_paise), taken off the order's own day.
 *   chargebacks        money taken back by the bank / gateway, recorded as an expense of category "chargeback" on the day it happened.
 *   net revenue        collected − refunds − chargebacks.
 *   provider cost      what the SMM panel charges for an AUTO order (orders.provider_cost_paise; "final" once the panel has reported its charge).
 *   fulfilment cost    what delivering a MANUAL order costs directly (order_costs, type fulfilment).
 *   payment fee        the gateway's fee, from the configured model (order_costs, type payment_fee). The gateway reports none.
 *   other order cost   any other direct cost of one order (order_costs, type other).
 *   contribution       collected − refunds − provider − fulfilment − payment fee − other order costs: what the orders earned before advertising,
 *                      chargebacks and overheads.
 *   advertising        ACTUAL spend (ad_daily_spend) for a day that has any, otherwise the CPC ESTIMATE (qualified visitors × CPC) — never both.
 *   expenses           business expenses of category tools / hosting / other, on the day recorded (voided ones are not counted).
 *   net profit         contribution − chargebacks − expenses − advertising.
 *   margin             net profit ÷ net revenue, only when net revenue is above zero (otherwise "—", never 0 %).
 *
 * UNKNOWN IS NOT ZERO. Every paid order has a cost status:
 *   actual      every applicable cost is a recorded actual (the panel's final charge, an admin-recorded line, no fee by the fee model);
 *   estimated   every applicable cost is known, at least one is an estimate (a configured service cost, a fee model, a panel charge not yet final);
 *   incomplete  an applicable cost is NOT known (an AUTO order with no provider cost, a MANUAL order with no fulfilment cost, a fee not set up).
 * A profit figure carries the worst status of the orders it adds up, and of its advertising days. Unknown costs are never subtracted as ₹0 and
 * called complete: the number is shown, labelled, and the reconciliation view lists what is missing.
 *
 * Cost lines are append-only: a correction ends the old line (superseded_at) and adds a new one, so changing a service's cost or the fee model
 * never rewrites an old order, and the history of who changed what stays (order_costs + audit log).
 */
final class Finance
{
    public const FEE_MODES = ['unset', 'none', 'percent', 'fixed', 'percent_plus_fixed'];
    public const COST_TYPES = ['fulfilment', 'payment_fee', 'other'];
    /** Business expense categories an admin can record. (`payment_fee` is not one: fees are per order, so a fee can never be subtracted twice.) */
    public const EXPENSE_CATEGORIES = ['tools', 'hosting', 'chargeback', 'other'];
    public const MAX_LINE_PAISE = 10_000_000;           // ₹1,00,000 on one order
    public const MAX_EXPENSE_PAISE = 100_000_000;       // ₹10,00,000 in one entry
    public const FEE_MAX_BPS = 2000;                    // a fee model above 20 % is a typing error
    public const FEE_MAX_FIXED_PAISE = 100_000;         // ₹1,000 per payment

    public const STATUS_ACTUAL = 'actual';
    public const STATUS_ESTIMATED = 'estimated';
    public const STATUS_INCOMPLETE = 'incomplete';

    /** Metric → what it means. Shown next to every figure and in the docs, so no number is a mystery. */
    public const DEFINITIONS = [
        'collected'      => 'Money from payments that were verified, on the day they were verified (Asia/Kolkata). Pending, failed and expired payments are not revenue.',
        'refunds'        => 'Refunds an admin recorded (the gateway has no refund API), taken off the day the order was paid.',
        'chargebacks'    => 'Money taken back by the bank, recorded as an expense of category “chargeback” on the day it happened.',
        'net_revenue'    => 'Collected − refunds − chargebacks.',
        'provider_cost'  => 'What the SMM panel charges for AUTO orders. An estimate until the panel reports its final charge.',
        'fulfilment'     => 'Direct cost of delivering MANUAL orders (staff time, a supplier …), from the service’s standard cost at the time of payment or a recorded amount.',
        'payment_fees'   => 'The gateway’s fee for each payment, from the fee model set under Settings (the gateway itself reports no fee).',
        'other_costs'    => 'Other direct costs recorded against single orders.',
        'contribution'   => 'Collected − refunds − provider cost − fulfilment − payment fees − other order costs: what the orders earned before advertising, chargebacks and overheads.',
        'advertising'    => 'Actual ad spend for a day that has any; otherwise the CPC estimate for that day (qualified visitors × CPC). Never both.',
        'expenses'       => 'Business expenses (tools, hosting, other) on the day they were recorded.',
        'net_profit'     => 'Contribution − chargebacks − expenses − advertising.',
        'margin'         => 'Net profit ÷ net revenue. Shown only when net revenue is above zero.',
        'aov'            => 'Average order value: collected ÷ paid orders.',
    ];

    // ═══════════════════════════ settings ═══════════════════════════════════

    /** @return array{fee_mode:string,fee_percent_bps:int,fee_fixed_paise:int,ad_gst:string} */
    public static function settings(): array
    {
        $mode = (string) Settings::get('finance.fee_mode', 'unset');
        $gst = (string) Settings::get('finance.ad_gst', 'cost');
        return [
            'fee_mode'        => in_array($mode, self::FEE_MODES, true) ? $mode : 'unset',
            'fee_percent_bps' => max(0, min(self::FEE_MAX_BPS, (int) Settings::get('finance.fee_percent_bps', 0))),
            'fee_fixed_paise' => max(0, min(self::FEE_MAX_FIXED_PAISE, (int) Settings::get('finance.fee_fixed_paise', 0))),
            'ad_gst'          => $gst === 'recoverable' ? 'recoverable' : 'cost',
        ];
    }

    /**
     * @param array<string,mixed> $in {fee_mode, fee_percent (%), fee_fixed (₹), ad_gst}
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function saveSettings(array $in, ?Request $req, array $admin): array
    {
        $old = self::settings();
        $errors = [];
        $mode = (string) ($in['fee_mode'] ?? $old['fee_mode']);
        if (!in_array($mode, self::FEE_MODES, true)) {
            $errors['fee_mode'] = 'Choose one of the listed fee models.';
        }
        $bps = $old['fee_percent_bps'];
        if (array_key_exists('fee_percent', $in)) {
            $p = is_string($in['fee_percent']) || is_int($in['fee_percent']) || is_float($in['fee_percent']) ? trim((string) $in['fee_percent']) : '';
            if ($p === '' || !preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $p) || (int) round((float) $p * 100) > self::FEE_MAX_BPS) {
                $errors['fee_percent'] = 'Enter a percentage from 0 to ' . (self::FEE_MAX_BPS / 100) . ' with at most two decimals.';
            } else {
                $bps = (int) round((float) $p * 100);
            }
        }
        $fixed = $old['fee_fixed_paise'];
        if (array_key_exists('fee_fixed', $in)) {
            try {
                $fixed = Money::parseRupees(is_string($in['fee_fixed']) || is_int($in['fee_fixed']) || is_float($in['fee_fixed']) ? $in['fee_fixed'] : null);
                if ($fixed > self::FEE_MAX_FIXED_PAISE) {
                    $errors['fee_fixed'] = 'A fixed fee above ₹' . Money::groupIndian((string) intdiv(self::FEE_MAX_FIXED_PAISE, 100)) . ' per payment is not accepted.';
                }
            } catch (\InvalidArgumentException) {
                $errors['fee_fixed'] = 'Enter the fixed fee in rupees with at most two decimals.';
            }
        }
        $gst = (string) ($in['ad_gst'] ?? $old['ad_gst']);
        if (!in_array($gst, ['cost', 'recoverable'], true)) {
            $errors['ad_gst'] = 'Choose whether the GST on advertising is a cost or recoverable.';
        }
        if (in_array($mode, ['percent', 'percent_plus_fixed'], true) && $bps === 0 && !isset($errors['fee_percent'])) {
            $errors['fee_percent'] = 'A percentage fee needs a percentage above 0. Choose “No fee” if the gateway charges nothing.';
        }
        if (in_array($mode, ['fixed', 'percent_plus_fixed'], true) && $fixed === 0 && !isset($errors['fee_fixed'])) {
            $errors['fee_fixed'] = 'A fixed fee needs an amount above ₹0. Choose “No fee” if the gateway charges nothing.';
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        $new = ['fee_mode' => $mode, 'fee_percent_bps' => $bps, 'fee_fixed_paise' => $fixed, 'ad_gst' => $gst];
        if ($new !== $old) {
            Settings::setMany([
                'finance.fee_mode' => $mode, 'finance.fee_percent_bps' => $bps, 'finance.fee_fixed_paise' => $fixed, 'finance.ad_gst' => $gst,
            ], $admin['id']);
            Audit::log('finance.settings_changed', 'settings', 'finance', ['from' => $old, 'to' => $new], $req, $admin);
            // the stored daily rows were computed with the old rules
            Db::exec('UPDATE analytics_daily SET dirty = 1');
        }
        return self::settings();
    }

    /** The payment fee for one payment under the fee model; null while the model is not set up (the fee is UNKNOWN, not zero). */
    public static function feeFor(int $amountPaise, ?array $settings = null): ?int
    {
        $s = $settings ?? self::settings();
        return match ($s['fee_mode']) {
            'none'               => 0,
            'percent'            => Money::mulDiv($amountPaise, $s['fee_percent_bps'], 10000),
            'fixed'              => $s['fee_fixed_paise'],
            'percent_plus_fixed' => Money::mulDiv($amountPaise, $s['fee_percent_bps'], 10000) + $s['fee_fixed_paise'],
            default              => null,
        };
    }

    public static function feeDescription(?array $settings = null): string
    {
        $s = $settings ?? self::settings();
        $pct = rtrim(rtrim(number_format($s['fee_percent_bps'] / 100, 2, '.', ''), '0'), '.');
        return match ($s['fee_mode']) {
            'none'               => 'no fee',
            'percent'            => $pct . '% of the payment',
            'fixed'              => Money::inr($s['fee_fixed_paise'], true) . ' per payment',
            'percent_plus_fixed' => $pct . '% + ' . Money::inr($s['fee_fixed_paise'], true) . ' per payment',
            default              => 'not set up',
        };
    }

    // ═══════════════════════════ cost lines ═════════════════════════════════

    /**
     * Snapshot what an order costs at the moment its payment is verified — inside the settlement transaction, so an order is never "paid" without
     * its cost lines. A later change of the fee model or of a service's cost does not touch it.
     *
     * @param array<string,mixed> $order the orders row (mode, service_id …)
     */
    public static function snapshotAtPayment(array $order, int $paidPaise, int $timestamp): void
    {
        $fee = self::feeFor($paidPaise);
        if ($fee !== null && self::settings()['fee_mode'] !== 'none') {
            self::insertLine((int) $order['id'], 'payment_fee', $fee, 'rate', true, 'Estimated from the fee model: ' . self::feeDescription(), null, $timestamp);
        }
        if (($order['mode'] ?? '') === 'MANUAL') {
            self::snapshotFulfilment($order, $timestamp);
        }
    }

    /** MANUAL orders: the service's standard direct cost at this moment (NULL = not stated yet: the order's profit stays "incomplete"). */
    public static function snapshotFulfilment(array $order, ?int $timestamp = null): void
    {
        $default = Db::val('SELECT fulfil_cost_paise FROM services WHERE id = ?', [$order['service_id']]);
        if ($default === null) {
            return;
        }
        self::insertLine((int) $order['id'], 'fulfilment', (int) $default, 'service_default', true, 'The service’s standard fulfilment cost when the order was paid', null, $timestamp ?? Clock::now());
    }

    private static function insertLine(int $orderId, string $type, int $paise, string $source, bool $estimate, ?string $note, ?int $adminId, int $timestamp): bool
    {
        $now = Clock::sql($timestamp);
        return Db::exec(
            'INSERT IGNORE INTO order_costs (order_id, cost_type, amount_paise, source, is_estimate, note, recorded_by, effective_at, created_at, active_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$orderId, $type, $paise, $source, $estimate ? 1 : 0, $note !== null ? mb_substr($note, 0, 255) : null, $adminId, $now, $now, in_array($type, ['fulfilment', 'payment_fee'], true) ? $orderId . ':' . $type : null]
        ) === 1;
    }

    /**
     * Record (or correct) a cost line of a PAID order. Fulfilment and payment fee have one live line per order: a correction ends the old line and adds
     * the new one. "Other" adds a further line. Needs a reason; audited; the order's timeline says so.
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed> the order's finance view
     */
    public static function recordLine(int $orderId, string $type, mixed $amountInput, string $note, bool $isEstimate, ?Request $req, array $admin): array
    {
        $errors = [];
        if (!in_array($type, self::COST_TYPES, true)) {
            $errors['type'] = 'Choose fulfilment, payment fee or other.';
        }
        $paise = 0;
        try {
            $paise = Money::parseRupees(is_string($amountInput) || is_int($amountInput) || is_float($amountInput) ? $amountInput : null);
            if ($paise > self::MAX_LINE_PAISE) {
                $errors['amount'] = 'A cost above ₹' . Money::groupIndian((string) intdiv(self::MAX_LINE_PAISE, 100)) . ' on one order is not accepted.';
            }
        } catch (\InvalidArgumentException) {
            $errors['amount'] = 'Enter the cost in rupees with at most two decimals (0 is allowed: it costs nothing).';
        }
        $note = trim(mb_substr($note, 0, 240));
        if (mb_strlen($note) < 3) {
            $errors['note'] = 'Say why (at least 3 characters): the change is kept in the order’s history.';
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        $paidDay = null;
        Db::tx(static function () use ($orderId, $type, $paise, $note, $isEstimate, $admin, &$paidDay): void {
            $o = Db::one('SELECT id, status, paid_at, paid_day, public_id FROM orders WHERE id = ? FOR UPDATE', [$orderId]) ?? throw new HttpException(404, 'Order not found.');
            if ($o['paid_at'] === null) {
                throw new HttpException(409, 'Costs are recorded for paid orders only.', 'not_paid');
            }
            $paidDay = (string) $o['paid_day'];
            $now = Clock::now();
            if (in_array($type, ['fulfilment', 'payment_fee'], true)) {
                Db::exec('UPDATE order_costs SET superseded_at = ?, active_key = NULL WHERE order_id = ? AND cost_type = ? AND superseded_at IS NULL', [Clock::sql($now), $orderId, $type]);
            }
            self::insertLine($orderId, $type, $paise, 'admin', $isEstimate, $note, $admin['id'], $now);
            Orders::history($orderId, (string) $o['status'], (string) $o['status'], 'admin', $admin['id'], 'Cost recorded: ' . str_replace('_', ' ', $type) . ' ' . Money::inr($paise, true) . ' — ' . $note, null, $admin['username']);
        });
        Audit::log('finance.cost_recorded', 'order', $orderId, ['type' => $type, 'amount' => Money::plain($paise), 'estimate' => $isEstimate, 'note' => $note], $req, $admin);
        if ($paidDay !== null) {
            Analytics::markDirty($paidDay);
        }
        return self::orderView($orderId);
    }

    /**
     * End a recorded line without replacing it (a wrong "other" cost). The line stays in the history.
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function voidLine(int $lineId, string $reason, ?Request $req, array $admin): array
    {
        $reason = trim(mb_substr($reason, 0, 200));
        if (mb_strlen($reason) < 3) {
            throw new HttpException(422, 'Say why (at least 3 characters).', 'invalid', ['fields' => ['reason' => 'Required.']]);
        }
        $orderId = 0;
        $day = null;
        Db::tx(static function () use ($lineId, $reason, $admin, &$orderId, &$day): void {
            $l = Db::one('SELECT c.*, o.paid_day, o.status FROM order_costs c JOIN orders o ON o.id = c.order_id WHERE c.id = ? FOR UPDATE', [$lineId]) ?? throw new HttpException(404, 'Cost line not found.');
            if ($l['superseded_at'] !== null) {
                throw new HttpException(409, 'That cost line is already closed.', 'conflict');
            }
            if ($l['cost_type'] !== 'other') {
                throw new HttpException(409, 'A fulfilment cost or payment fee is corrected by recording the right amount, not removed.', 'not_allowed');
            }
            $orderId = (int) $l['order_id'];
            $day = (string) $l['paid_day'];
            Db::exec('UPDATE order_costs SET superseded_at = ?, active_key = NULL, note = ? WHERE id = ?', [Clock::sql(), mb_substr(trim((string) $l['note']) . ' [removed: ' . $reason . ']', 0, 255), $lineId]);
            Orders::history($orderId, (string) $l['status'], (string) $l['status'], 'admin', $admin['id'], 'Cost line removed: other ' . Money::inr((int) $l['amount_paise'], true) . ' — ' . $reason, null, $admin['username']);
        });
        Audit::log('finance.cost_removed', 'order', $orderId, ['line' => $lineId, 'reason' => $reason], $req, $admin);
        if ($day !== null) {
            Analytics::markDirty($day);
        }
        return self::orderView($orderId);
    }

    // ═══════════════════════════ cost status ════════════════════════════════

    /** SQL (alias `$o`): the cost of this paid order is NOT fully known. */
    public static function incompleteSql(string $o = 'o'): string
    {
        $feeNeeded = self::settings()['fee_mode'] === 'none' ? '0' : '1';
        return "(({$o}.mode = 'AUTO' AND {$o}.provider_cost_final = 0 AND {$o}.provider_cost_paise = 0 AND NOT ({$o}.provider_order_id IS NULL AND {$o}.status IN ('FAILED', 'CANCELLED', 'REFUNDED', 'EXPIRED')))"
            . " OR ({$o}.mode = 'MANUAL' AND NOT EXISTS (SELECT 1 FROM order_costs fc WHERE fc.order_id = {$o}.id AND fc.cost_type = 'fulfilment' AND fc.superseded_at IS NULL))"
            . " OR ({$feeNeeded} = 1 AND NOT EXISTS (SELECT 1 FROM order_costs pf WHERE pf.order_id = {$o}.id AND pf.cost_type = 'payment_fee' AND pf.superseded_at IS NULL)))";
    }

    /** SQL (alias `$o`): every cost is known but at least one is an estimate. (Evaluate after incompleteSql.) */
    public static function estimatedSql(string $o = 'o'): string
    {
        return "(({$o}.mode = 'AUTO' AND {$o}.provider_cost_final = 0 AND {$o}.provider_cost_paise > 0)"
            . " OR EXISTS (SELECT 1 FROM order_costs ec WHERE ec.order_id = {$o}.id AND ec.superseded_at IS NULL AND ec.is_estimate = 1))";
    }

    /** SQL (alias `$o`): the sum of an order's live cost lines (fulfilment + payment fee + other). */
    public static function linesSql(string $o = 'o'): string
    {
        return "COALESCE((SELECT SUM(lc.amount_paise) FROM order_costs lc WHERE lc.order_id = {$o}.id AND lc.superseded_at IS NULL), 0)";
    }

    /**
     * The same rules for one order, in PHP (the tests assert the SQL and this agree).
     *
     * @param array<string,mixed> $o orders row
     * @param list<array<string,mixed>> $lines live order_costs rows of the order
     * @return array{status:string,reasons:list<string>}
     */
    public static function costStatus(array $o, array $lines, ?array $settings = null): array
    {
        $s = $settings ?? self::settings();
        $has = static fn(string $type): bool => (bool) array_filter($lines, static fn(array $l): bool => $l['cost_type'] === $type);
        $missing = [];
        $estimated = [];
        if ($o['mode'] === 'AUTO') {
            $neverSent = $o['provider_order_id'] === null && in_array($o['status'], ['FAILED', 'CANCELLED', 'REFUNDED', 'EXPIRED'], true);
            if ((int) $o['provider_cost_final'] === 0) {
                if ((int) $o['provider_cost_paise'] === 0 && !$neverSent) {
                    $missing[] = 'The provider’s cost is not known yet.';
                } elseif ((int) $o['provider_cost_paise'] > 0) {
                    $estimated[] = 'The provider’s final charge has not been reported: the estimate from the service is used.';
                }
            }
        } elseif (!$has('fulfilment')) {
            $missing[] = 'No fulfilment cost: the service has no standard cost and none was recorded for this order.';
        }
        if ($s['fee_mode'] !== 'none' && !$has('payment_fee')) {
            $missing[] = $s['fee_mode'] === 'unset' ? 'The payment fee model is not set up.' : 'No payment fee line was recorded for this order.';
        }
        foreach ($lines as $l) {
            if ((int) $l['is_estimate'] === 1) {
                $estimated[] = ucfirst(str_replace('_', ' ', (string) $l['cost_type'])) . ' is an estimate (' . self::sourceLabel((string) $l['source']) . ').';
            }
        }
        if ($missing) {
            return ['status' => self::STATUS_INCOMPLETE, 'reasons' => $missing];
        }
        return $estimated ? ['status' => self::STATUS_ESTIMATED, 'reasons' => array_values(array_unique($estimated))] : ['status' => self::STATUS_ACTUAL, 'reasons' => []];
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'service_default' => 'the service’s standard cost',
            'rate'            => 'the fee model',
            'admin'           => 'recorded by an admin',
            default           => $source,
        };
    }

    /**
     * One order's money, line by line: what was collected, every cost with its source and time, the contribution and how complete it is.
     *
     * @return array<string,mixed>
     */
    public static function orderView(int $orderId): array
    {
        $o = Orders::byId($orderId) ?? throw new HttpException(404, 'Order not found.');
        $paid = $o['paid_at'] !== null;
        $lines = Db::all(
            'SELECT c.*, a.username FROM order_costs c LEFT JOIN admins a ON a.id = c.recorded_by WHERE c.order_id = ? ORDER BY c.id',
            [$orderId]
        );
        $live = array_values(array_filter($lines, static fn(array $l): bool => $l['superseded_at'] === null));
        $status = $paid ? self::costStatus($o, $live) : ['status' => null, 'reasons' => []];
        $revenue = (int) $o['amount_paise'] - (int) $o['refunded_paise'];
        $provider = $o['mode'] === 'AUTO' ? (int) $o['provider_cost_paise'] : 0;
        $lineTotal = array_sum(array_map(static fn(array $l): int => (int) $l['amount_paise'], $live));
        return [
            'order_id' => $orderId, 'public_id' => $o['public_id'], 'paid' => $paid, 'mode' => $o['mode'], 'paid_day' => $o['paid_day'],
            'collected_paise' => $paid ? (int) $o['amount_paise'] : 0, 'refunded_paise' => (int) $o['refunded_paise'],
            'provider_cost_paise' => $provider, 'provider_cost_final' => (bool) $o['provider_cost_final'],
            'line_costs_paise' => $lineTotal,
            'contribution_paise' => $paid ? $revenue - $provider - $lineTotal : null,
            'cost_status' => $status['status'], 'reasons' => $status['reasons'],
            'lines' => array_map(static fn(array $l): array => [
                'id' => (int) $l['id'], 'type' => $l['cost_type'], 'amount_paise' => (int) $l['amount_paise'], 'source' => $l['source'], 'source_label' => self::sourceLabel((string) $l['source']),
                'estimate' => (bool) $l['is_estimate'], 'note' => $l['note'], 'by' => $l['username'], 'at' => Clock::iso($l['effective_at']),
                'active' => $l['superseded_at'] === null, 'ended_at' => Clock::iso($l['superseded_at']),
            ], $lines),
            'fee_model' => self::feeDescription(),
        ];
    }

    // ═══════════════════════════ expenses ═══════════════════════════════════

    /**
     * @param array<string,mixed> $in {day, category, amount, description?, reference?, order?}
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function addExpense(array $in, ?Request $req, array $admin): array
    {
        $errors = [];
        $day = trim((string) ($in['day'] ?? ''));
        if (!Tz::isDay($day) || $day > Tz::today() || $day < '2020-01-01') {
            $errors['day'] = 'Choose a date that is not in the future.';
        }
        $category = (string) ($in['category'] ?? '');
        if (!in_array($category, self::EXPENSE_CATEGORIES, true)) {
            $errors['category'] = 'Choose tools, hosting, chargeback or other.';
        }
        $paise = 0;
        try {
            $paise = Money::parseRupees(is_string($in['amount'] ?? null) || is_int($in['amount'] ?? null) || is_float($in['amount'] ?? null) ? $in['amount'] : null);
            if ($paise < 1 || $paise > self::MAX_EXPENSE_PAISE) {
                $errors['amount'] = 'Enter an amount above ₹0 and up to ₹' . Money::groupIndian((string) intdiv(self::MAX_EXPENSE_PAISE, 100)) . '.';
            }
        } catch (\InvalidArgumentException) {
            $errors['amount'] = 'Enter the amount in rupees with at most two decimals.';
        }
        $desc = trim(mb_substr((string) ($in['description'] ?? ''), 0, 255));
        if ($category === 'other' && mb_strlen($desc) < 3) {
            $errors['description'] = 'Say what this expense is (at least 3 characters).';
        }
        $reference = trim(mb_substr((string) ($in['reference'] ?? ''), 0, 120));
        $orderId = null;
        $orderRef = trim((string) ($in['order'] ?? ''));
        if ($orderRef !== '') {
            $orderId = Db::val('SELECT id FROM orders WHERE public_id = ?', [strtoupper($orderRef)]);
            if ($orderId === null) {
                $errors['order'] = 'No order has that id.';
            }
        }
        if ($category === 'chargeback' && $orderId === null && !isset($errors['order'])) {
            $errors['order'] = 'Name the order the chargeback is for (SMB-…).';
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        $id = Db::insert('expenses', [
            'expense_day' => $day, 'category' => $category, 'amount_paise' => $paise, 'order_id' => $orderId, 'description' => $desc !== '' ? $desc : null,
            'reference' => $reference !== '' ? $reference : null, 'source' => 'manual', 'created_by' => $admin['id'], 'created_at' => Clock::sql(),
        ]);
        Audit::log('finance.expense_added', 'expense', $id, ['day' => $day, 'category' => $category, 'amount' => Money::plain($paise), 'order' => $orderRef !== '' ? strtoupper($orderRef) : null], $req, $admin);
        Analytics::markDirty($day);
        return self::expenseRow((int) Db::val('SELECT id FROM expenses WHERE id = ?', [$id]));
    }

    /** @param array{id:int,username:string,email:string} $admin @return array<string,mixed> */
    public static function voidExpense(int $id, string $reason, ?Request $req, array $admin): array
    {
        $reason = trim(mb_substr($reason, 0, 200));
        if (mb_strlen($reason) < 3) {
            throw new HttpException(422, 'Say why (at least 3 characters).', 'invalid', ['fields' => ['reason' => 'Required.']]);
        }
        $day = null;
        Db::tx(static function () use ($id, $reason, $admin, &$day): void {
            $e = Db::one('SELECT * FROM expenses WHERE id = ? FOR UPDATE', [$id]) ?? throw new HttpException(404, 'Expense not found.');
            if ($e['voided_at'] !== null) {
                throw new HttpException(409, 'That expense is already voided.', 'conflict');
            }
            $day = (string) $e['expense_day'];
            Db::update('expenses', ['voided_at' => Clock::sql(), 'voided_by' => $admin['id'], 'void_reason' => $reason], 'id = ?', [$id]);
        });
        Audit::log('finance.expense_voided', 'expense', $id, ['reason' => $reason], $req, $admin);
        if ($day !== null) {
            Analytics::markDirty($day);
        }
        return self::expenseRow($id);
    }

    /** @return array<string,mixed> */
    private static function expenseRow(int $id): array
    {
        $r = Db::one(
            'SELECT e.*, o.public_id AS order_public, a.username AS by_name, v.username AS voided_by_name FROM expenses e LEFT JOIN orders o ON o.id = e.order_id '
            . 'LEFT JOIN admins a ON a.id = e.created_by LEFT JOIN admins v ON v.id = e.voided_by WHERE e.id = ?',
            [$id]
        ) ?? throw new HttpException(404, 'Expense not found.');
        return self::shapeExpense($r);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function shapeExpense(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'day' => (string) $r['expense_day'], 'category' => (string) $r['category'], 'amount_paise' => (int) $r['amount_paise'],
            'order' => $r['order_public'], 'description' => $r['description'], 'reference' => $r['reference'], 'by' => $r['by_name'], 'at' => Clock::iso($r['created_at']),
            'voided' => $r['voided_at'] !== null, 'voided_at' => Clock::iso($r['voided_at']), 'voided_by' => $r['voided_by_name'], 'void_reason' => $r['void_reason'],
        ];
    }

    /**
     * @param array{from?:string,to?:string,category?:string,include_voided?:bool,page?:int} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per:int,sum_paise:int}
     */
    public static function expenses(array $f): array
    {
        $per = 25;
        $where = ['1 = 1'];
        $args = [];
        if (!empty($f['from']) && Tz::isDay((string) $f['from'])) {
            $where[] = 'e.expense_day >= ?';
            $args[] = $f['from'];
        }
        if (!empty($f['to']) && Tz::isDay((string) $f['to'])) {
            $where[] = 'e.expense_day <= ?';
            $args[] = $f['to'];
        }
        if (!empty($f['category']) && in_array($f['category'], self::EXPENSE_CATEGORIES, true)) {
            $where[] = 'e.category = ?';
            $args[] = $f['category'];
        }
        if (empty($f['include_voided'])) {
            $where[] = 'e.voided_at IS NULL';
        }
        $w = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM expenses e WHERE {$w}", $args);
        $sum = (int) Db::val("SELECT COALESCE(SUM(e.amount_paise), 0) FROM expenses e WHERE {$w} AND e.voided_at IS NULL", $args);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = Db::all(
            "SELECT e.*, o.public_id AS order_public, a.username AS by_name, v.username AS voided_by_name FROM expenses e LEFT JOIN orders o ON o.id = e.order_id "
            . "LEFT JOIN admins a ON a.id = e.created_by LEFT JOIN admins v ON v.id = e.voided_by WHERE {$w} ORDER BY e.expense_day DESC, e.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $args
        );
        return ['rows' => array_map([self::class, 'shapeExpense'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'sum_paise' => $sum];
    }

    // ═══════════════════════════ daily aggregates (used by Analytics::computeDays) ══════════

    /**
     * Cost lines of the orders PAID on each day, per mode (so the AUTO / MANUAL split of profit stays exact).
     *
     * @param list<mixed> $op parameters of $of
     * @return array<string,array{AUTO:array{fulfil:int,fee:int,other:int},MANUAL:array{fulfil:int,fee:int,other:int}}>
     */
    public static function lineCostsByDay(string $from, string $to, string $of, array $op): array
    {
        $out = [];
        $rows = Db::all(
            'SELECT o.paid_day AS d, o.mode AS mode, '
            . "SUM(CASE WHEN c.cost_type = 'fulfilment' THEN c.amount_paise ELSE 0 END) AS fulfil, "
            . "SUM(CASE WHEN c.cost_type = 'payment_fee' THEN c.amount_paise ELSE 0 END) AS fee, "
            . "SUM(CASE WHEN c.cost_type = 'other' THEN c.amount_paise ELSE 0 END) AS other "
            . 'FROM order_costs c JOIN orders o ON o.id = c.order_id WHERE c.superseded_at IS NULL AND o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?' . $of
            . ' GROUP BY o.paid_day, o.mode',
            array_merge([$from, $to], $op)
        );
        foreach ($rows as $r) {
            $out[(string) $r['d']][(string) $r['mode']] = ['fulfil' => (int) $r['fulfil'], 'fee' => (int) $r['fee'], 'other' => (int) $r['other']];
        }
        return $out;
    }

    /**
     * Chargebacks and business expenses recorded on each day (site-wide; voided entries never count).
     *
     * @return array<string,array{chargebacks:int,expenses:int}>
     */
    public static function expensesByDay(string $from, string $to): array
    {
        $out = [];
        foreach (Db::all(
            "SELECT expense_day AS d, SUM(CASE WHEN category = 'chargeback' THEN amount_paise ELSE 0 END) AS cb, "
            . "SUM(CASE WHEN category <> 'chargeback' THEN amount_paise ELSE 0 END) AS ex FROM expenses WHERE voided_at IS NULL AND expense_day BETWEEN ? AND ? GROUP BY expense_day",
            [$from, $to]
        ) as $r) {
            $out[(string) $r['d']] = ['chargebacks' => (int) $r['cb'], 'expenses' => (int) $r['ex']];
        }
        return $out;
    }

    /**
     * How many orders paid on each day have an unknown / an estimated cost.
     *
     * @param list<mixed> $op
     * @return array<string,array{incomplete:int,estimated:int}>
     */
    public static function statusByDay(string $from, string $to, string $of, array $op): array
    {
        $inc = self::incompleteSql('o');
        $out = [];
        foreach (Db::all(
            "SELECT o.paid_day AS d, SUM(CASE WHEN {$inc} THEN 1 ELSE 0 END) AS inc, SUM(CASE WHEN NOT {$inc} AND " . self::estimatedSql('o') . ' THEN 1 ELSE 0 END) AS est '
            . 'FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?' . $of . ' GROUP BY o.paid_day',
            array_merge([$from, $to], $op)
        ) as $r) {
            $out[(string) $r['d']] = ['incomplete' => (int) $r['inc'], 'estimated' => (int) $r['est']];
        }
        return $out;
    }
}
