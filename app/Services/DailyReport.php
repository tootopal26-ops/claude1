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
 * The daily report: what one business day (Asia/Kolkata by default) looked like, calculated from the database and stored once.
 *
 *   build($day)      the numbers, straight from the source tables (the same Analytics / Finance code as the dashboard) — no AI, no estimate that is
 *                    not labelled. Nothing is written.
 *   generate($day)   builds and stores the report. `daily_reports.report_date` is UNIQUE, so a second run for the same day creates nothing: the
 *                    cron job only fills days that have no report, and an admin can regenerate one explicitly (the old and new figures are compared
 *                    in the audit log, and any AI commentary is dropped when the figures changed, so commentary never describes numbers that are gone).
 *   runDue($ctx)     the `report` job: every finished day since the first business day (at most the last 7) that has no report yet.
 *
 * Every metric carries a BASIS:
 *   measured     counted from verified records (a paid order, a verified payment, a recorded refund, a spend entry)
 *   estimated    at least one input is an estimate (a payment fee from the fee model, a provider charge the panel has not confirmed, CPC-based
 *                advertising, a manual cost taken from the service’s standard cost)
 *   incomplete   an input is unknown (an order whose cost nobody entered): the figure is a floor, not the truth
 *   unavailable  cannot be calculated from the data (no paid orders → no average order value; too few visitors → no conversion rate)
 *
 * The snapshot holds integers (paise, counts, tenths of a percent) only. An AI provider (Phase 7) may add commentary in separate columns; it can
 * never change a number here.
 */
final class DailyReport
{
    public const CALC_VERSION = '1';
    /** How many finished days back the job looks for a missing report. */
    public const CATCHUP_DAYS = 7;
    /** Conversion needs at least this many qualified visitors in the day to mean anything. */
    public const MIN_VISITORS_FOR_CONVERSION = 30;
    private const PER_PAGE = 20;

    /** Display order of the metrics: section => [title, keys]. */
    public const SECTIONS = [
        'sales'    => ['Sales', ['collected', 'refunds', 'chargebacks', 'net_revenue', 'orders_paid', 'aov']],
        'orders'   => ['Orders paid this day, by where they are now', ['orders_created', 'orders_completed', 'orders_processing', 'orders_waiting', 'orders_failed']],
        'payments' => ['Payments', ['payments_success', 'payments_pending', 'payments_failed', 'payments_expired']],
        'costs'    => ['Costs', ['provider_cost', 'fulfilment', 'payment_fees', 'other_costs', 'advertising', 'expenses']],
        'profit'   => ['Profit', ['contribution', 'net_profit', 'margin']],
        'audience' => ['Visitors', ['visitors', 'qualified', 'conversion']],
    ];

    /** The figures compared with the previous day. */
    private const COMPARE = ['collected', 'net_revenue', 'orders_paid', 'aov', 'provider_cost', 'advertising', 'net_profit', 'payments_failed'];

    // ═══════════════════════════ building ═══════════════════════════════════

    /**
     * @return array<string,mixed> the snapshot (integers and strings only)
     */
    public static function build(string $day): array
    {
        $prev = Tz::addDays($day, -1);
        $first = Analytics::firstDay();
        $days = Analytics::computeDays($prev, $day);
        $cur = $days[$day];
        $t = Analytics::sum([$cur]);
        $x = FinanceReport::metrics($t);
        $quality = FinanceReport::labels($t, false, [$day => $cur]);
        $start = Tz::dayStartSql($day);
        $end = Tz::dayStartSql(Tz::addDays($day, 1));

        $metrics = self::metrics($day, $t, $x, $quality, $start, $end);
        $comparison = self::compare($metrics, $prev >= $first ? self::metricsForComparison($prev, Analytics::sum([$days[$prev]])) : null, $prev);
        $services = self::services($day);
        $failures = self::failures($day, $start, $end);
        $alerts = self::alerts($start, $end);
        $fee = Finance::settings();

        return [
            'version' => self::CALC_VERSION,
            'date' => $day,
            'timezone' => Tz::name(),
            'window_utc' => [$start, $end],
            'built_at' => Clock::iso(Clock::sql()),
            'metrics' => $metrics,
            'comparison' => $comparison,
            'services' => $services,
            'failures' => $failures,
            'alerts' => $alerts,
            'quality' => ['status' => $quality['status'], 'reasons' => $quality['reasons'], 'ads' => $quality['ads']],
            'highlights' => self::highlights($metrics, $comparison, $services, $failures, $alerts, $quality),
            'caveats' => self::caveats($fee['fee_mode']),
        ];
    }

    /**
     * @param array<string,int> $t Analytics::sum of the day
     * @param array<string,mixed> $x FinanceReport::metrics
     * @param array<string,mixed> $quality FinanceReport::labels
     * @return array<string,array{label:string,value:?int,unit:string,basis:string,note:string}>
     */
    private static function metrics(string $day, array $t, array $x, array $quality, string $start, string $end): array
    {
        $m = [];
        $add = static function (string $key, string $label, ?int $value, string $unit, string $basis, string $note = '') use (&$m): void {
            $m[$key] = ['label' => $label, 'value' => $value, 'unit' => $unit, 'basis' => $value === null ? 'unavailable' : $basis, 'note' => $note];
        };

        // how the orders paid on this day stand now
        $by = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM orders WHERE paid_at IS NOT NULL AND paid_day = ? GROUP BY status', [$day]) as $r) {
            $by[(string) $r['status']] = (int) $r['n'];
        }
        $sum = static fn(array $statuses): int => array_sum(array_map(static fn(string $s): int => $by[$s] ?? 0, $statuses));
        $pendingPayments = (int) Db::val("SELECT COUNT(*) FROM payments WHERE created_day = ? AND paid_at IS NULL AND status IN ('creating', 'pending')", [$day]);

        // how reliable each cost is for the orders paid on this day
        $cost = self::costBasis($day);
        $profitBasis = $quality['status'] === Finance::STATUS_INCOMPLETE ? 'incomplete' : ($quality['status'] === Finance::STATUS_ESTIMATED ? 'estimated' : 'measured');
        $profitNote = $quality['reasons'] ? implode(' ', $quality['reasons']) : '';

        $add('collected', 'Verified revenue', $x['collected'], 'paise', 'measured', 'Money from payments verified on this day, before refunds.');
        $add('refunds', 'Refunds', $x['refunds'], 'paise', 'measured', 'Refunds an admin recorded against orders paid on this day.');
        $add('chargebacks', 'Chargebacks', $x['chargebacks'], 'paise', 'measured', 'Recorded as expenses of category chargeback on this day. Only what was entered.');
        $add('net_revenue', 'Net revenue', $x['net_revenue'], 'paise', 'measured', 'Verified revenue − refunds − chargebacks.');
        $add('orders_paid', 'Paid orders', $x['orders_paid'], 'count', 'measured');
        $add('aov', 'Average order value', $x['aov'], 'paise', 'measured', $x['aov'] === null ? 'No paid orders this day.' : '');
        $add('orders_created', 'Orders created', $x['orders_created'], 'count', 'measured', 'Orders placed on this day, paid or not.');
        $add('orders_completed', 'Completed', $x['orders_completed'], 'count', 'measured', 'Status when this report was generated.');
        $add('orders_processing', 'Still processing', $sum(['PAID', 'PROCESSING', 'SUBMITTED', 'IN_PROGRESS']), 'count', 'measured', 'Paid, with the provider or being sent.');
        $add('orders_waiting', 'Manual, waiting for approval', $sum(['MANUAL_PENDING']), 'count', 'measured');
        $add('orders_failed', 'Failed, cancelled, refunded or expired', $sum(['FAILED', 'CANCELLED', 'REFUNDED', 'EXPIRED']), 'count', 'measured', 'A paid order that did not end well; partial deliveries are not counted here (' . $sum(['PARTIAL']) . ' on this day).');
        $add('payments_success', 'Successful payments', $x['payments_success'], 'count', 'measured', 'Verified on this day.');
        $add('payments_pending', 'Pending payments', $pendingPayments, 'count', 'measured', 'Started on this day and still waiting for a result.');
        $add('payments_failed', 'Failed payments', $x['payments_failed'], 'count', 'measured', 'Started on this day; the gateway reported a failure or the amount did not match.');
        $add('payments_expired', 'Expired payment sessions', $x['payments_expired'], 'count', 'measured', 'Customers who did not pay within the session. Normal in small numbers.');

        $add('provider_cost', 'API (provider) costs', $x['provider_cost'], 'paise', $cost['provider'][0], $cost['provider'][1]);
        $add('fulfilment', 'Manual fulfilment costs', $x['fulfilment'], 'paise', $cost['fulfilment'][0], $cost['fulfilment'][1]);
        $add('payment_fees', 'Payment fees', $x['payment_fees'], 'paise', $cost['fees'][0], $cost['fees'][1]);
        $add('other_costs', 'Other order costs', $x['other_costs'], 'paise', 'measured', 'Costs an admin recorded against single orders.');
        $adsBasis = $x['ads_actual_days'] > 0 ? 'measured' : ($x['ads_estimated'] > 0 ? 'estimated' : 'measured');
        $add('advertising', 'Advertising spend', $x['advertising'], 'paise', $adsBasis, $x['ads_actual_days'] > 0 ? 'Actual spend entered for this day.' : ($x['ads_estimated'] > 0 ? 'The CPC estimate (qualified visitors × CPC): no actual spend was entered for this day.' : 'No spend entered and no qualified visitors.'));
        $add('expenses', 'Business expenses', $x['expenses'], 'paise', 'measured', 'Tools, hosting and other expenses recorded on this day. Only what was entered.');

        $add('contribution', 'Contribution before advertising', $x['contribution'], 'paise', $profitBasis, $profitNote);
        $add('net_profit', 'Net profit', $x['net_profit'], 'paise', $profitBasis, $profitNote);
        $add('margin', 'Margin', $x['margin_tenths'], 'tenths_pct', $profitBasis, $x['margin_tenths'] === null ? 'Net revenue is zero, so there is no margin to state.' : $profitNote);

        $add('visitors', 'Visitors', $t['visitors'], 'count', 'measured', 'Distinct visitors with a session on this day.');
        $add('qualified', 'Qualified visitors', $t['qualified_visitors'], 'count', 'measured', 'Stayed on the page for the qualifying time.');
        $enough = $t['qualified_visitors'] >= self::MIN_VISITORS_FOR_CONVERSION;
        $add(
            'conversion',
            'Conversion rate',
            $enough ? Money::pctTenths($t['paid_visitors'], $t['qualified_visitors']) : null,
            'tenths_pct',
            'measured',
            $enough ? 'Visitors who paid ÷ qualified visitors.' : 'Only ' . $t['qualified_visitors'] . ' qualified visitors; at least ' . self::MIN_VISITORS_FOR_CONVERSION . ' are needed for a conversion rate to mean anything.'
        );
        return $m;
    }

    /**
     * How reliable the provider, fulfilment and payment-fee figures of the orders paid on a day are.
     *
     * @return array{provider:array{0:string,1:string},fulfilment:array{0:string,1:string},fees:array{0:string,1:string}}
     */
    private static function costBasis(string $day): array
    {
        $o = Db::one(
            "SELECT SUM(CASE WHEN mode = 'AUTO' THEN 1 ELSE 0 END) AS auto_n, "
            . "SUM(CASE WHEN mode = 'AUTO' AND provider_cost_final = 0 AND provider_cost_paise > 0 THEN 1 ELSE 0 END) AS auto_est, "
            . "SUM(CASE WHEN mode = 'AUTO' AND provider_cost_final = 0 AND provider_cost_paise = 0 AND NOT (provider_order_id IS NULL AND status IN ('FAILED', 'CANCELLED', 'REFUNDED', 'EXPIRED')) THEN 1 ELSE 0 END) AS auto_unknown, "
            . "SUM(CASE WHEN mode = 'MANUAL' THEN 1 ELSE 0 END) AS manual_n, COUNT(*) AS all_n FROM orders WHERE paid_at IS NOT NULL AND paid_day = ?",
            [$day]
        ) ?? [];
        $lines = [];
        foreach (Db::all(
            'SELECT c.cost_type AS type, COUNT(*) AS n, SUM(c.is_estimate) AS est FROM order_costs c JOIN orders o ON o.id = c.order_id WHERE c.superseded_at IS NULL AND o.paid_at IS NOT NULL AND o.paid_day = ? GROUP BY c.cost_type',
            [$day]
        ) as $r) {
            $lines[(string) $r['type']] = ['n' => (int) $r['n'], 'est' => (int) $r['est']];
        }
        $manualNoLine = (int) Db::val(
            "SELECT COUNT(*) FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day = ? AND o.mode = 'MANUAL' AND NOT EXISTS (SELECT 1 FROM order_costs fc WHERE fc.order_id = o.id AND fc.cost_type = 'fulfilment' AND fc.superseded_at IS NULL)",
            [$day]
        );
        $noFeeLine = (int) Db::val(
            "SELECT COUNT(*) FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day = ? AND NOT EXISTS (SELECT 1 FROM order_costs pf WHERE pf.order_id = o.id AND pf.cost_type = 'payment_fee' AND pf.superseded_at IS NULL)",
            [$day]
        );
        $autoN = (int) ($o['auto_n'] ?? 0);
        $manualN = (int) ($o['manual_n'] ?? 0);
        $allN = (int) ($o['all_n'] ?? 0);

        $provider = match (true) {
            $autoN === 0 => ['measured', 'No AUTO orders were paid on this day.'],
            (int) $o['auto_unknown'] > 0 => ['incomplete', $o['auto_unknown'] . ' AUTO order' . ((int) $o['auto_unknown'] === 1 ? ' has' : 's have') . ' no known provider cost, counted as ₹0.'],
            (int) $o['auto_est'] > 0 => ['estimated', $o['auto_est'] . ' AUTO order' . ((int) $o['auto_est'] === 1 ? ' uses' : 's use') . ' the service’s standard cost: the provider has not reported its final charge.'],
            default => ['measured', 'The provider’s reported charge for every AUTO order.'],
        };
        $fulfilment = match (true) {
            $manualN === 0 => ['measured', 'No manual orders were paid on this day.'],
            $manualNoLine > 0 => ['incomplete', $manualNoLine . ' manual order' . ($manualNoLine === 1 ? ' has' : 's have') . ' no fulfilment cost, counted as ₹0.'],
            ($lines['fulfilment']['est'] ?? 0) > 0 => ['estimated', 'Taken from the services’ standard costs, not from a cost recorded on the order.'],
            default => ['measured', 'Costs recorded on the orders.'],
        };
        $mode = Finance::settings()['fee_mode'];
        $fees = match (true) {
            $mode === 'none' => ['measured', 'The payment fee model is “no fee”: the gateway charges nothing.'],
            $allN === 0 => ['measured', 'No orders were paid on this day.'],
            $noFeeLine > 0 => ['incomplete', $noFeeLine . ' order' . ($noFeeLine === 1 ? ' has' : 's have') . ' no payment-fee line' . ($mode === 'unset' ? ' (the fee model is not set up)' : '') . ', counted as ₹0.'],
            ($lines['payment_fee']['est'] ?? 0) > 0 => ['estimated', 'Calculated from the fee model — the payment gateway reports no fee.'],
            default => ['measured', 'Fees recorded on the orders.'],
        };
        return ['provider' => $provider, 'fulfilment' => $fulfilment, 'fees' => $fees];
    }

    /**
     * @param array<string,int> $t
     * @return array<string,?int> the compared figures of another day
     */
    private static function metricsForComparison(string $day, array $t): array
    {
        $x = FinanceReport::metrics($t);
        return [
            'collected' => $x['collected'], 'net_revenue' => $x['net_revenue'], 'orders_paid' => $x['orders_paid'], 'aov' => $x['aov'],
            'provider_cost' => $x['provider_cost'], 'advertising' => $x['advertising'], 'net_profit' => $x['net_profit'], 'payments_failed' => $x['payments_failed'],
        ];
    }

    /**
     * This day against the one before it. Nothing is said about a day that is before the first business day, and no percentage about a zero.
     *
     * @param array<string,array<string,mixed>> $metrics
     * @param array<string,?int>|null $prev
     * @return array{previous_date:string,available:bool,rows:list<array<string,mixed>>}
     */
    private static function compare(array $metrics, ?array $prev, string $prevDay): array
    {
        $rows = [];
        if ($prev !== null) {
            foreach (self::COMPARE as $key) {
                $now = $metrics[$key]['value'];
                $then = $prev[$key] ?? null;
                $delta = $now !== null && $then !== null ? $now - $then : null;
                $rows[] = [
                    'key' => $key, 'label' => $metrics[$key]['label'], 'unit' => $metrics[$key]['unit'], 'value' => $now, 'previous' => $then, 'delta' => $delta,
                    'delta_pct_tenths' => ($delta !== null && $then !== null && $then !== 0) ? Money::pctTenths($delta, abs($then)) : null,
                    'direction' => $delta === null ? 'na' : ($delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat')),
                ];
            }
        }
        return ['previous_date' => $prevDay, 'available' => $prev !== null, 'rows' => $rows];
    }

    /**
     * `missing_cost` lists only services that SOLD on the day with a cost that is not known — those are the ones that change the day’s profit.
     * Active services that have no cost set but did not sell are only counted (`no_cost_unsold`): they are a to-do for Finance, not news about this day.
     *
     * @return array{most_profitable:list<array<string,mixed>>,loss_making:list<array<string,mixed>>,missing_cost:list<array<string,mixed>>,no_cost_unsold:int}
     */
    private static function services(string $day): array
    {
        $s = FinanceReport::services($day, $day);
        $trim = static fn(array $r): array => [
            'code' => (string) $r['code'], 'title' => (string) $r['title'], 'orders' => (int) $r['orders'], 'collected' => (int) ($r['collected'] ?? 0),
            'contribution' => (int) ($r['contribution'] ?? 0), 'margin_tenths' => $r['margin_tenths'] ?? null, 'status' => (string) ($r['status'] ?? ''),
        ];
        $sold = array_values(array_filter($s['missing_cost'], static fn(array $r): bool => (int) ($r['orders'] ?? 0) > 0));
        return [
            'most_profitable' => array_map($trim, array_slice($s['most_profitable'], 0, 5)),
            'loss_making' => array_map($trim, array_slice($s['loss_making'], 0, 5)),
            'missing_cost' => array_map(static fn(array $r): array => ['code' => (string) ($r['code'] ?? ''), 'title' => (string) ($r['title'] ?? ''), 'orders' => (int) ($r['orders'] ?? 0), 'why' => (string) ($r['why'] ?? '')], array_slice($sold, 0, 8)),
            'no_cost_unsold' => count($s['missing_cost']) - count($sold),
        ];
    }

    /**
     * API and payment failures of the day, from the records themselves.
     *
     * @return array<string,mixed>
     */
    private static function failures(string $day, string $start, string $end): array
    {
        $w = Db::one(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN signature_valid = 0 OR status = 'invalid' THEN 1 ELSE 0 END) AS invalid_n, SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_n, "
            . "SUM(CASE WHEN status = 'mismatch' THEN 1 ELSE 0 END) AS mismatch_n FROM webhook_events WHERE received_at >= ? AND received_at < ?",
            [$start, $end]
        ) ?? [];
        $providers = [];
        foreach (Db::all(
            'SELECT x.provider_id AS id, MAX(p.name) AS name, COUNT(*) AS n, SUM(CASE WHEN x.ok = 0 THEN 1 ELSE 0 END) AS bad, '
            . 'SUM(CASE WHEN x.ok = 0 AND (x.http_status IS NULL OR x.http_status >= 500 OR x.error LIKE \'The provider returned a non-JSON%\') THEN 1 ELSE 0 END) AS technical '
            . "FROM smm_transactions x JOIN smm_providers p ON p.id = x.provider_id WHERE x.created_at >= ? AND x.created_at < ? AND x.action IN ('add', 'status') GROUP BY x.provider_id ORDER BY x.provider_id",
            [$start, $end]
        ) as $r) {
            $providers[] = ['name' => (string) $r['name'], 'calls' => (int) $r['n'], 'failed' => (int) $r['bad'], 'technical' => (int) $r['technical'], 'rejected' => (int) $r['bad'] - (int) $r['technical']];
        }
        $kinds = [];
        foreach (Db::all("SELECT COALESCE(failure_kind, 'unspecified') AS kind, COUNT(*) AS n FROM orders WHERE paid_at IS NOT NULL AND paid_day = ? AND status = 'FAILED' GROUP BY failure_kind", [$day]) as $r) {
            $kinds[(string) $r['kind']] = (int) $r['n'];
        }
        $pay = Db::one(
            "SELECT SUM(CASE WHEN paid_at IS NULL AND status = 'failed' THEN 1 ELSE 0 END) AS failed_n, SUM(CASE WHEN paid_at IS NULL AND status = 'mismatch' THEN 1 ELSE 0 END) AS mismatch_n FROM payments WHERE created_day = ?",
            [$day]
        ) ?? [];
        return [
            'payments' => ['failed' => (int) ($pay['failed_n'] ?? 0), 'amount_or_state_mismatch' => (int) ($pay['mismatch_n'] ?? 0)],
            'webhooks' => ['received' => (int) ($w['total'] ?? 0), 'invalid_signature' => (int) ($w['invalid_n'] ?? 0), 'unprocessed' => (int) ($w['error_n'] ?? 0), 'mismatch' => (int) ($w['mismatch_n'] ?? 0)],
            'provider_calls' => $providers,
            'orders_failed_by_kind' => $kinds,
            'needs_person_now' => self::needsPerson(),
        ];
    }

    private static function needsPerson(): int
    {
        try {
            return (int) Recovery::summary()['needs_person'];
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The watchdog alerts: those still unresolved when the report is built, and how many were first detected / resolved on the day.
     *
     * @return array<string,mixed>
     */
    private static function alerts(string $start, string $end): array
    {
        $active = array_map(static fn(array $a): array => [
            'severity' => $a['severity'], 'title' => $a['title'], 'type' => $a['type'], 'status' => $a['status'], 'occurrences' => $a['occurrences'],
            'first_detected_at' => $a['first_detected_at'], 'last_detected_at' => $a['last_detected_at'],
        ], array_slice(Alerts::list(['status' => 'active'])['rows'], 0, 15));
        return [
            'as_of' => Clock::iso(Clock::sql()), 'counts' => Alerts::counts(), 'unresolved' => $active,
            'detected_on_day' => (int) Db::val('SELECT COUNT(*) FROM business_alerts WHERE first_detected_at >= ? AND first_detected_at < ?', [$start, $end]),
            'resolved_on_day' => (int) Db::val('SELECT COUNT(*) FROM business_alerts WHERE resolved_at >= ? AND resolved_at < ?', [$start, $end]),
        ];
    }

    /**
     * A few plain sentences written by fixed rules from the numbers above. No AI wrote them.
     *
     * @param array<string,array<string,mixed>> $m
     * @param array<string,mixed> $comparison
     * @param array<string,mixed> $services
     * @param array<string,mixed> $failures
     * @param array<string,mixed> $alerts
     * @param array<string,mixed> $quality
     * @return list<array{tone:string,text:string}>
     */
    private static function highlights(array $m, array $comparison, array $services, array $failures, array $alerts, array $quality): array
    {
        $out = [];
        $inr = static fn(int $p): string => Money::inr($p, true);
        $paid = (int) $m['orders_paid']['value'];
        if ($paid === 0) {
            $out[] = ['tone' => 'info', 'text' => 'No order was paid on this day.'];
        } else {
            $line = 'Verified revenue ' . $inr((int) $m['collected']['value']) . ' from ' . $paid . ' paid order' . ($paid === 1 ? '' : 's') . '.';
            foreach ($comparison['rows'] as $r) {
                if ($r['key'] === 'collected' && $r['delta_pct_tenths'] !== null) {
                    $line .= ' ' . ($r['delta'] >= 0 ? 'Up ' : 'Down ') . Money::tenthsToString(abs((int) $r['delta_pct_tenths'])) . '% on the previous day (' . $inr((int) $r['previous']) . ').';
                } elseif ($r['key'] === 'collected' && $r['previous'] === 0) {
                    $line .= ' The previous day had no sales to compare with.';
                }
            }
            $out[] = ['tone' => 'info', 'text' => $line];
        }
        $basis = $m['net_profit']['basis'];
        if ($basis === 'incomplete') {
            $out[] = ['tone' => 'warn', 'text' => implode(' ', $quality['reasons']) . ' The net profit shown (' . $inr((int) $m['net_profit']['value']) . ') counts every unknown cost as ₹0.'];
        } elseif ($paid > 0 || (int) $m['net_profit']['value'] !== 0) {
            $net = (int) $m['net_profit']['value'];
            $out[] = ['tone' => $net < 0 ? 'bad' : 'good', 'text' => ($basis === 'estimated' ? 'Estimated net profit ' : 'Net profit ') . $inr($net) . ($m['margin']['value'] !== null ? ' (margin ' . Money::tenthsToString((int) $m['margin']['value']) . '%)' : '') . '.' . ($basis === 'estimated' ? ' Some inputs are estimates — see the notes.' : '')];
        }
        $failed = (int) $m['payments_failed']['value'];
        $expired = (int) $m['payments_expired']['value'];
        if ($failed > 0) {
            $out[] = ['tone' => 'warn', 'text' => $failed . ' payment' . ($failed === 1 ? '' : 's') . ' failed and ' . $expired . ' session' . ($expired === 1 ? '' : 's') . ' expired unpaid (' . (int) $m['payments_success']['value'] . ' succeeded).'];
        }
        if ($services['loss_making']) {
            $names = array_map(static fn(array $s): string => $s['title'], array_slice($services['loss_making'], 0, 3));
            $out[] = ['tone' => 'warn', 'text' => count($services['loss_making']) . ' service' . (count($services['loss_making']) === 1 ? '' : 's') . ' lost money on this day: ' . implode(', ', $names) . '.'];
        }
        $calls = array_sum(array_column($failures['provider_calls'], 'calls'));
        $bad = array_sum(array_column($failures['provider_calls'], 'failed'));
        if ($bad > 0) {
            $out[] = ['tone' => 'warn', 'text' => $bad . ' of ' . $calls . ' calls to the SMM provider failed.'];
        }
        if ((int) $failures['needs_person_now'] > 0) {
            $out[] = ['tone' => 'warn', 'text' => $failures['needs_person_now'] . ' paid order' . ((int) $failures['needs_person_now'] === 1 ? '' : 's') . ' need' . ((int) $failures['needs_person_now'] === 1 ? 's' : '') . ' a person right now (Order recovery).'];
        }
        $c = $alerts['counts'];
        if ($c['total'] > 0) {
            $out[] = ['tone' => $c['critical'] > 0 ? 'bad' : 'warn', 'text' => $c['total'] . ' unresolved watchdog alert' . ($c['total'] === 1 ? '' : 's') . ($c['critical'] > 0 ? ' (' . $c['critical'] . ' critical)' : '') . ' when this report was built.'];
        }
        return $out;
    }

    /** @return list<string> */
    private static function caveats(string $feeMode): array
    {
        $out = [
            'Refunds are taken off the day the order was paid, not the day the refund was recorded.',
            'Order statuses are as of the moment this report was generated, not as of the end of the day.',
            'Chargebacks and expenses are only what an admin recorded.',
        ];
        if ($feeMode !== 'none') {
            $out[] = 'Payment fees come from the fee model set in Finance → Settings; the payment gateway itself reports no fee.';
        }
        $out[] = 'Everything here is calculated by fixed rules from the shop’s own records. No AI model took part in these numbers.';
        return $out;
    }

    // ═══════════════════════════ storing ════════════════════════════════════

    /**
     * Build and store the report of a day.
     *
     * Without `force` an existing report is left alone (this is what the cron job uses). With `force` — an administrator asked for it — the
     * report is rebuilt: the row is updated in place (never a second row), the number of regenerations goes up, the figures that changed are
     * written to the audit log, and AI commentary is dropped if any figure changed.
     *
     * @param array{by?:string,force?:bool,admin?:array{id:int,username:string,email:string},req?:Request} $opts
     * @return array{date:string,created:bool,regenerated:bool,changed:list<array<string,mixed>>}
     */
    public static function generate(string $day, array $opts = []): array
    {
        self::assertDay($day);
        $force = (bool) ($opts['force'] ?? false);
        $by = ($opts['by'] ?? 'cron') === 'admin' ? 'admin' : 'cron';
        $admin = $opts['admin'] ?? null;
        $req = $opts['req'] ?? null;

        $existing = Db::one('SELECT snapshot_json FROM daily_reports WHERE report_date = ?', [$day]);
        if ($existing !== null && !$force) {
            return ['date' => $day, 'created' => false, 'regenerated' => false, 'changed' => []];
        }
        $snapshot = self::build($day);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($existing === null) {
            $n = Db::exec(
                "INSERT IGNORE INTO daily_reports (report_date, calc_version, snapshot_json, generated_at, generated_by, regenerated_count, ai_status) VALUES (?, ?, ?, ?, ?, 0, 'none')",
                [$day, self::CALC_VERSION, $json, Clock::sql(), $by]
            );
            if ($n === 1) {
                if ($admin !== null) {
                    Audit::log('report.generated', 'daily_report', $day, ['calc_version' => self::CALC_VERSION], $req, $admin);
                }
                return ['date' => $day, 'created' => true, 'regenerated' => false, 'changed' => []];
            }
            if (!$force) {
                return ['date' => $day, 'created' => false, 'regenerated' => false, 'changed' => []];       // another run stored it first
            }
            $existing = Db::one('SELECT snapshot_json FROM daily_reports WHERE report_date = ?', [$day]) ?? ['snapshot_json' => '{}'];
        }

        $changed = self::diff(json_decode((string) $existing['snapshot_json'], true) ?: [], $snapshot);
        Db::exec(
            'UPDATE daily_reports SET calc_version = ?, snapshot_json = ?, generated_at = ?, generated_by = ?, regenerated_count = regenerated_count + 1 WHERE report_date = ?',
            [self::CALC_VERSION, $json, Clock::sql(), $by, $day]
        );
        if ($changed) {
            Db::exec("UPDATE daily_reports SET ai_status = 'none', ai_provider = NULL, ai_model = NULL, ai_summary = NULL, ai_generated_at = NULL WHERE report_date = ?", [$day]);
        }
        Audit::log('report.regenerated', 'daily_report', $day, ['changed' => array_slice($changed, 0, 20), 'figures_changed' => count($changed)], $req, $admin);
        return ['date' => $day, 'created' => false, 'regenerated' => true, 'changed' => $changed];
    }

    /**
     * Which figures differ between two snapshots (value or basis).
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return list<array{key:string,before:?int,after:?int,basis_before:?string,basis_after:string}>
     */
    private static function diff(array $old, array $new): array
    {
        $out = [];
        foreach ($new['metrics'] as $key => $m) {
            $o = $old['metrics'][$key] ?? null;
            if ($o === null || ($o['value'] ?? null) !== $m['value'] || ($o['basis'] ?? null) !== $m['basis']) {
                $out[] = ['key' => (string) $key, 'before' => $o['value'] ?? null, 'after' => $m['value'], 'basis_before' => $o['basis'] ?? null, 'basis_after' => $m['basis']];
            }
        }
        return $out;
    }

    /**
     * The `report` job: write the report of every finished day since the first business day (at most the last CATCHUP_DAYS) that has none.
     *
     * @return array<string,mixed>
     */
    public static function runDue(JobContext $c): array
    {
        $missing = self::missingDays(self::CATCHUP_DAYS);
        $out = ['missing' => count($missing), 'generated' => [], 'skipped_existing' => 0];
        foreach ($missing as $day) {
            if ($c->expired()) {
                $out['stopped_early'] = true;
                break;
            }
            if ($c->dryRun) {
                $out['would_generate'][] = $day;
                continue;
            }
            $r = self::generate($day, ['by' => 'cron']);
            if ($r['created']) {
                $out['generated'][] = $day;
            } else {
                $out['skipped_existing']++;
            }
        }
        return $out;
    }

    /**
     * Finished days (up to yesterday) without a report, oldest first, never before the first business day.
     *
     * @return list<string>
     */
    public static function missingDays(int $limit = self::CATCHUP_DAYS): array
    {
        $yesterday = Tz::addDays(Tz::today(), -1);
        $from = max(Analytics::firstDay(), Tz::addDays($yesterday, -($limit - 1)));
        if ($from > $yesterday) {
            return [];
        }
        $have = array_flip(array_map('strval', Db::col('SELECT report_date FROM daily_reports WHERE report_date BETWEEN ? AND ?', [$from, $yesterday])));
        $out = [];
        for ($d = $from; $d <= $yesterday; $d = Tz::addDays($d, 1)) {
            if (!isset($have[$d])) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** A report covers one whole, finished business day that has data behind it. */
    public static function assertDay(string $day): void
    {
        if (!Tz::isDay($day)) {
            throw new HttpException(422, 'Enter a date like 2026-10-15.', 'invalid', ['fields' => ['date' => 'Enter a date like 2026-10-15.']]);
        }
        $yesterday = Tz::addDays(Tz::today(), -1);
        if ($day > $yesterday) {
            throw new HttpException(422, 'A report covers a whole finished day: the latest you can generate is ' . $yesterday . '.', 'invalid', ['fields' => ['date' => 'The latest day is ' . $yesterday . '.']]);
        }
        $first = Analytics::firstDay();
        if ($day < $first) {
            throw new HttpException(422, 'The shop has no data before ' . $first . '.', 'invalid', ['fields' => ['date' => 'The first day with data is ' . $first . '.']]);
        }
    }

    // ═══════════════════════════ reading ════════════════════════════════════

    /**
     * The report history, newest first.
     *
     * @param array{from?:string,to?:string,page?:int} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per:int,missing_days:list<string>}
     */
    public static function list(array $f = []): array
    {
        $where = [];
        $args = [];
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $v = (string) ($f[$k] ?? '');
            if ($v !== '') {
                if (!Tz::isDay($v)) {
                    throw new HttpException(422, 'Dates must look like 2026-10-15.', 'invalid', ['fields' => [$k => 'Enter a date like 2026-10-15.']]);
                }
                $where[] = "report_date {$op} ?";
                $args[] = $v;
            }
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Db::val("SELECT COUNT(*) FROM daily_reports {$w}", $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = Db::all(
            "SELECT report_date, calc_version, snapshot_json, generated_at, generated_by, regenerated_count, ai_status FROM daily_reports {$w} ORDER BY report_date DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        );
        return [
            'rows' => array_map([self::class, 'summary'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => self::PER_PAGE,
            'missing_days' => self::missingDays(30),
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function summary(array $r): array
    {
        $s = json_decode((string) $r['snapshot_json'], true) ?: [];
        $m = $s['metrics'] ?? [];
        return [
            'date' => (string) $r['report_date'], 'calc_version' => (string) $r['calc_version'], 'generated_at' => Clock::iso($r['generated_at']), 'generated_by' => (string) $r['generated_by'],
            'regenerated_count' => (int) $r['regenerated_count'], 'ai_status' => (string) $r['ai_status'],
            'collected' => $m['collected']['value'] ?? null, 'orders_paid' => $m['orders_paid']['value'] ?? null, 'net_profit' => $m['net_profit']['value'] ?? null,
            'net_profit_basis' => $m['net_profit']['basis'] ?? 'unavailable', 'margin' => $m['margin']['value'] ?? null,
            'quality' => $s['quality']['status'] ?? null, 'alerts_unresolved' => $s['alerts']['counts']['total'] ?? null, 'alerts_critical' => $s['alerts']['counts']['critical'] ?? null,
        ];
    }

    /**
     * One report with everything needed to show it.
     *
     * @return array<string,mixed>|null
     */
    public static function get(string $day): ?array
    {
        if (!Tz::isDay($day)) {
            return null;
        }
        $r = Db::one('SELECT * FROM daily_reports WHERE report_date = ?', [$day]);
        if ($r === null) {
            return null;
        }
        return self::summary($r) + [
            'snapshot' => json_decode((string) $r['snapshot_json'], true) ?: [],
            'sections' => self::SECTIONS,
            'definitions' => Finance::DEFINITIONS,
            'ai' => ['status' => (string) $r['ai_status'], 'provider' => $r['ai_provider'], 'model' => $r['ai_model'], 'summary' => $r['ai_summary'], 'generated_at' => Clock::iso($r['ai_generated_at'])],
        ];
    }
}
