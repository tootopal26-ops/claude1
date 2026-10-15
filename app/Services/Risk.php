<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Payments\Gateways;
use App\Payments\PaymentStatusResult;
use App\Support\Clock;
use App\Support\Status;

/**
 * Payment checks: what looks wrong about a payment, kept as FLAGS for a person to look at.
 *
 * A flag is a signal, never a punishment: nothing here blocks a customer, bans a visitor or moves money. The things that protect the money are
 * elsewhere and unchanged — a payment is only ever credited from a signed webhook or the gateway's own status lookup, for exactly the stored
 * amount in INR (Settlement). This service records when that did NOT hold (amount / currency / reference problems), when something is merely
 * unusual (a late payment, repeated unfinished attempts, a webhook for a payment the shop does not have) and when two sources disagree
 * (the webhook says paid, the gateway's lookup says otherwise).
 *
 * One live flag per problem: `active_key` holds the dedupe key while a flag is open and is NULL once it is reviewed, dismissed or resolved, and a
 * unique index on it makes that a database guarantee. Seeing the same problem again only raises `occurrences`; a recurrence after a review opens a new flag.
 * A high-severity flag also raises a Business Watchdog alert (e-mail only if the owner enabled it), after the surrounding transaction has committed.
 *
 * The cron job `payments` calls scan(): it re-derives the conditions that can be detected from the data alone (inconsistent order / payment
 * records, bursts of failed signatures, repeated unfinished attempts), double-checks recent webhook-paid payments with the gateway, and closes
 * the flags whose condition no longer holds.
 */
final class Risk
{
    public const SEVERITIES = ['low', 'medium', 'high'];

    /** type => [default severity, label, what it means and what to do] */
    public const TYPES = [
        'amount_mismatch'      => ['high', 'Amount mismatch', 'The gateway reported a different amount from the order, so nothing was fulfilled. Check what actually reached the bank account and confirm by hand only if the full amount arrived.'],
        'currency_mismatch'    => ['high', 'Currency is not INR', 'The gateway reported the payment in a currency other than INR. Nothing was fulfilled; check the gateway dashboard before deciding.'],
        'missing_amount'       => ['medium', 'Amount not reported', 'The gateway said the payment succeeded but did not say how much was paid, so the amount could not be verified. Check the bank statement.'],
        'duplicate_reference'  => ['high', 'Bank reference already used', 'This bank reference (UTR) already belongs to another payment. Nothing was fulfilled. Compare both payments before confirming anything.'],
        'duplicate_payment'    => ['medium', 'Paid more than once', 'A second payment arrived for an order that was already paid. The order is fulfilled once; the extra money has to be refunded by hand (the gateway has no refund API).'],
        'late_payment'         => ['low', 'Paid after the session ended', 'The payment was confirmed after its 5-minute session expired. It was credited because the money arrived; nothing needs doing unless the amount looks wrong.'],
        'unknown_reference'    => ['medium', 'Unknown order reference', 'A correctly signed notification named a payment this shop does not have. Often another site on the same gateway account; look into it only if it keeps happening.'],
        'conflicting_status'   => ['high', 'Gateway answers disagree', 'The webhook and the gateway’s own status lookup do not agree (see the details). Check the gateway dashboard before shipping more of this order.'],
        'invalid_signature'    => ['medium', 'Webhook signatures failing', 'Several notifications failed signature verification and were rejected. If you changed the webhook secret, update it under Payment gateway; otherwise someone may be probing the endpoint.'],
        'repeated_failures'    => ['low', 'Repeated unfinished payments', 'The same visitor started several payments in a short time and none was completed. Usually a customer struggling with a UPI app; nothing is blocked.'],
        'mapping_inconsistent' => ['high', 'Order and payment disagree', 'The order and its payment records do not agree (see the details). Nothing is changed automatically; open the order and decide.'],
    ];

    /** Defaults and limits of the thresholds an owner may change (Settings key `risk.<name>`). */
    public const DEFAULTS = [
        'invalid_signatures_per_hour' => 5,
        'failed_attempts'             => 4,
        'failed_window_hours'         => 24,
        'flag_late_payments'          => true,
        'webhook_double_check'        => 'flag',
        'confirm_grace_seconds'       => 120,
    ];
    private const BOUNDS = [
        'invalid_signatures_per_hour' => [1, 1000], 'failed_attempts' => [2, 50], 'failed_window_hours' => [1, 168], 'confirm_grace_seconds' => [30, 3600],
    ];
    public const DOUBLE_CHECK_MODES = ['off', 'flag', 'require'];
    /** How many findings one detector of the scan looks at per run; a full page may hide more, so nothing is closed on the strength of one. */
    private const SCAN_PAGE = 50;

    /** @var list<array{0:string,1:string,2:string,3:array<string,mixed>,4:string}> alerts waiting for the surrounding transaction to commit */
    private static array $pending = [];

    // ── thresholds ───────────────────────────────────────────────────────────

    /** @return array{invalid_signatures_per_hour:int,failed_attempts:int,failed_window_hours:int,flag_late_payments:bool,webhook_double_check:string,confirm_grace_seconds:int} */
    public static function thresholds(): array
    {
        $int = static function (string $k): int {
            [$lo, $hi] = self::BOUNDS[$k];
            return max($lo, min($hi, (int) Settings::get('risk.' . $k, self::DEFAULTS[$k])));
        };
        $mode = (string) Settings::get('risk.webhook_double_check', self::DEFAULTS['webhook_double_check']);
        return [
            'invalid_signatures_per_hour' => $int('invalid_signatures_per_hour'),
            'failed_attempts'             => $int('failed_attempts'),
            'failed_window_hours'         => $int('failed_window_hours'),
            'flag_late_payments'          => (bool) Settings::get('risk.flag_late_payments', self::DEFAULTS['flag_late_payments']),
            'webhook_double_check'        => in_array($mode, self::DOUBLE_CHECK_MODES, true) ? $mode : self::DEFAULTS['webhook_double_check'],
            'confirm_grace_seconds'       => $int('confirm_grace_seconds'),
        ];
    }

    /** @param array<string,mixed> $in @param array{id:int,username:string,email:string} $admin @return array<string,mixed> */
    public static function saveSettings(array $in, array $admin, ?Request $req = null): array
    {
        $errors = [];
        $set = [];
        foreach (self::BOUNDS as $k => [$lo, $hi]) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = $in[$k];
            if (!is_int($v) && !(is_string($v) && preg_match('/^\d{1,6}$/D', $v))) {
                $errors[$k] = 'Enter a whole number.';
            } elseif ((int) $v < $lo || (int) $v > $hi) {
                $errors[$k] = "Enter a number from {$lo} to {$hi}.";
            } else {
                $set['risk.' . $k] = (int) $v;
            }
        }
        if (array_key_exists('flag_late_payments', $in)) {
            if (!is_bool($in['flag_late_payments'])) {
                $errors['flag_late_payments'] = 'Choose on or off.';
            } else {
                $set['risk.flag_late_payments'] = $in['flag_late_payments'];
            }
        }
        if (array_key_exists('webhook_double_check', $in)) {
            if (!in_array($in['webhook_double_check'], self::DOUBLE_CHECK_MODES, true)) {
                $errors['webhook_double_check'] = 'Choose off, flag or require.';
            } else {
                $set['risk.webhook_double_check'] = $in['webhook_double_check'];
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Some of the settings are not valid.', 'invalid', ['fields' => $errors]);
        }
        if ($set) {
            Settings::setMany($set, $admin['id']);
            Audit::log('risk.settings_changed', 'settings', 'risk', $set, $req, $admin);
        }
        return self::settingsView();
    }

    /** @return array<string,mixed> */
    public static function settingsView(): array
    {
        return ['values' => self::thresholds(), 'defaults' => self::DEFAULTS, 'bounds' => self::BOUNDS, 'modes' => self::DOUBLE_CHECK_MODES];
    }

    // ── recording ────────────────────────────────────────────────────────────

    /**
     * Record (or re-record) a flag. The same problem seen again raises occurrences on the one live flag instead of adding rows.
     *
     * @param array{payment_id?:?int,order_id?:?int,gateway_id?:?int,webhook_event_id?:?int,key?:string,severity?:string,details?:array<string,mixed>} $ctx
     * @return array{id:int,created:bool,occurrences:int}
     */
    public static function record(string $type, array $ctx = []): array
    {
        $meta = self::TYPES[$type] ?? throw new \InvalidArgumentException("Unknown payment flag type '{$type}'.");
        $severity = in_array($ctx['severity'] ?? '', self::SEVERITIES, true) ? (string) $ctx['severity'] : $meta[0];
        $paymentId = isset($ctx['payment_id']) ? (int) $ctx['payment_id'] : null;
        $orderId = isset($ctx['order_id']) ? (int) $ctx['order_id'] : null;
        if ($orderId === null && $paymentId !== null) {
            $v = Db::val('SELECT order_id FROM payments WHERE id = ?', [$paymentId]);
            $orderId = $v !== null ? (int) $v : null;
        }
        $gatewayId = isset($ctx['gateway_id']) ? (int) $ctx['gateway_id'] : null;
        $key = mb_substr($type . ':' . (string) ($ctx['key'] ?? ($paymentId !== null ? 'p' . $paymentId : 'x')), 0, 160);
        $details = self::cleanDetails($ctx['details'] ?? []);
        $now = Clock::sql();

        $n = Db::exec(
            'INSERT INTO payment_flags (flag_type, severity, payment_id, order_id, gateway_id, webhook_event_id, dedupe_key, active_key, status, occurrences, first_seen_at, last_seen_at, details_json) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', 1, ?, ?, ?) "
            . 'ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen_at = VALUES(last_seen_at), details_json = VALUES(details_json), '
            . "severity = IF(FIELD(VALUES(severity), 'low', 'medium', 'high') > FIELD(severity, 'low', 'medium', 'high'), VALUES(severity), severity)",
            [$type, $severity, $paymentId, $orderId, $gatewayId, $ctx['webhook_event_id'] ?? null, $key, $key, $now, $now, $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null]
        );
        $row = Db::one('SELECT id, severity, occurrences FROM payment_flags WHERE active_key = ?', [$key]);
        if ($row === null) {
            return ['id' => 0, 'created' => false, 'occurrences' => 0];
        }
        if ($row['severity'] === 'high') {
            self::alertFor($type, $key, $orderId, $paymentId, $details);
        }
        return ['id' => (int) $row['id'], 'created' => $n === 1, 'occurrences' => (int) $row['occurrences']];
    }

    /**
     * Raise the alerts that were held back while a database transaction was open (Settlement records its flags inside the transaction that
     * marks the payment; an e-mail must not be sent from inside one). A held alert whose flag was rolled back is dropped.
     */
    public static function flush(): void
    {
        $items = self::$pending;
        self::$pending = [];
        foreach ($items as [$title, $description, $key, $opts, $flagKey]) {
            try {
                if (Db::val('SELECT 1 FROM payment_flags WHERE active_key = ?', [$flagKey]) === null) {
                    continue;
                }
                Alerts::raise('payment_flag', 'critical', $title, $description, ['dedupe_key' => $key] + $opts);
            } catch (\Throwable $e) {
                Log::exception($e, 'Could not raise a payment alert');
            }
        }
    }

    /** @param array<string,mixed> $details */
    private static function alertFor(string $type, string $flagKey, ?int $orderId, ?int $paymentId, array $details): void
    {
        $publicId = $orderId !== null ? Db::val('SELECT public_id FROM orders WHERE id = ?', [$orderId]) : null;
        $title = 'Payment needs review: ' . self::TYPES[$type][1];
        $description = self::TYPES[$type][1] . ($publicId !== null ? ' on order ' . $publicId : '') . '. ' . self::TYPES[$type][2] . ' Open Payment checks in the admin panel.';
        $opts = ['entity_type' => 'payment', 'entity_id' => $paymentId, 'details' => $details];
        $item = [$title, $description, mb_substr('pf:' . $flagKey, 0, 120), $opts, $flagKey];
        if (Db::inTransaction()) {
            self::$pending[] = $item;
            return;
        }
        self::$pending[] = $item;
        self::flush();
    }

    /** Only plain facts are kept: scalars, short, cleaned. No payloads, no secrets. @param array<string,mixed> $details @return array<string,scalar> */
    private static function cleanDetails(array $details): array
    {
        $out = [];
        foreach ($details as $k => $v) {
            if (count($out) >= 14 || !is_scalar($v)) {
                continue;
            }
            $out[mb_substr((string) $k, 0, 40)] = is_string($v) ? mb_substr(Jobs::clean($v), 0, 200) : $v;
        }
        return $out;
    }

    // ── closing flags ────────────────────────────────────────────────────────

    /** The condition no longer holds: close the live flag(s) with these dedupe keys (resolution = automatic). @param list<string> $keys */
    private static function resolveKeys(array $keys): int
    {
        $n = 0;
        foreach ($keys as $key) {
            $done = Db::exec(
                "UPDATE payment_flags SET status = 'resolved', active_key = NULL, reviewed_at = ?, review_note = ? WHERE active_key = ?",
                [Clock::sql(), 'Cleared automatically: the condition no longer holds.', $key]
            );
            if ($done > 0) {
                Alerts::clear(mb_substr('pf:' . $key, 0, 120));
                $n += $done;
            }
        }
        return $n;
    }

    /**
     * Close open flags of $type whose key is not among those still found (the scan re-derives them every run). A flag that the scan does not
     * derive itself — e.g. money that arrived for a cancelled order, recorded by the settlement — is never closed here: only keys that end in
     * one of $kinds are the scan's to close.
     *
     * @param list<string> $stillFound
     * @param list<string>|null $kinds
     */
    private static function resolveMissing(string $type, array $stillFound, bool $write, ?array $kinds = null): int
    {
        $stale = [];
        foreach (Db::col('SELECT dedupe_key FROM payment_flags WHERE flag_type = ? AND active_key IS NOT NULL', [$type]) as $key) {
            $key = (string) $key;
            if (in_array($key, $stillFound, true)) {
                continue;
            }
            if ($kinds !== null && !array_filter($kinds, static fn(string $k): bool => str_ends_with($key, ':' . $k))) {
                continue;
            }
            $stale[] = $key;
        }
        return $write ? self::resolveKeys($stale) : count($stale);
    }

    /**
     * A person looked at the flag. "reviewed" = handled / understood; "dismissed" = a false alarm (a note saying why is required).
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function review(int $id, string $status, string $note, array $admin, ?Request $req = null): array
    {
        if (!in_array($status, ['reviewed', 'dismissed'], true)) {
            throw new HttpException(422, 'Choose reviewed or dismissed.', 'invalid');
        }
        $note = trim(mb_substr($note, 0, 255));
        if ($status === 'dismissed' && $note === '') {
            throw new HttpException(422, 'Add a short note saying why this is a false alarm.', 'invalid', ['fields' => ['note' => 'Required.']]);
        }
        $flag = Db::one('SELECT * FROM payment_flags WHERE id = ?', [$id]) ?? throw new HttpException(404, 'Flag not found.');
        $n = Db::exec(
            "UPDATE payment_flags SET status = ?, active_key = NULL, reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ? AND status = 'open'",
            [$status, $admin['id'], Clock::sql(), $note !== '' ? $note : null, $id]
        );
        if ($n === 0) {
            throw new HttpException(409, 'This flag is already ' . $flag['status'] . '.', 'conflict');
        }
        Alerts::clear(mb_substr('pf:' . $flag['dedupe_key'], 0, 120));
        Audit::log('payment_flag.' . $status, 'payment_flag', $id, ['type' => $flag['flag_type'], 'payment' => $flag['payment_id'], 'note' => $note], $req, $admin);
        return self::flagRow(self::flagQuery('f.id = ?', [$id])[0]);
    }

    // ── gateway cross-checks ─────────────────────────────────────────────────

    /** Ask the gateway about a payment, server to server. Null when it cannot be asked or did not answer. */
    private static function lookup(array $p): ?PaymentStatusResult
    {
        $row = $p['gateway_id'] !== null ? Gateways::row((int) $p['gateway_id']) : null;
        if ($row === null) {
            return null;
        }
        try {
            $r = Gateways::adapter($row)->verifyPayment((string) $p['gateway_order_id'], (int) $p['amount_paise']);
        } catch (\Throwable $e) {
            Log::exception($e, 'Gateway status check failed', ['payment' => $p['id']]);
            return null;
        }
        return $r->reachable ? $r : null;
    }

    private static function touch(int $paymentId): void
    {
        Db::exec('UPDATE payments SET last_polled_at = ?, poll_count = poll_count + 1 WHERE id = ?', [Clock::sql(), $paymentId]);
    }

    /**
     * Compare the gateway's answer with a payment we recorded as paid. It only ever records or clears flags — it never changes the payment
     * or the order — so it is safe to repeat. A "not paid" answer a few seconds after a webhook may just be the gateway catching up, so unless
     * $final it is not flagged yet (the scan asks again after a grace period).
     *
     * @return string confirmed | conflict | not_confirmed
     */
    private static function compare(array $p, PaymentStatusResult $r, bool $final): string
    {
        $id = (int) $p['id'];
        $base = ['payment_id' => $id, 'order_id' => (int) $p['order_id'], 'gateway_id' => $p['gateway_id'] !== null ? (int) $p['gateway_id'] : null];
        $mine = ['recorded_amount_paise' => (int) $p['amount_paise'], 'recorded_via' => (string) ($p['verified_via'] ?? '')];

        if ($r->status !== PaymentStatusResult::SUCCESS) {
            if (!$final) {
                return 'not_confirmed';
            }
            self::touch($id);
            self::record('conflicting_status', $base + ['key' => "p{$id}:webhook_not_confirmed", 'details' => $mine + ['kind' => 'webhook_not_confirmed', 'gateway_status' => $r->status]]);
            return 'not_confirmed';
        }
        self::touch($id);
        $problems = [];
        if ($r->amountPaise !== null && $r->amountPaise !== (int) $p['amount_paise']) {
            $problems['amount_differs'] = ['gateway_amount_paise' => $r->amountPaise];
        }
        if ($r->currency !== null && $r->currency !== 'INR') {
            $problems['currency_differs'] = ['gateway_currency' => $r->currency];
        }
        if ($r->utr !== null && $r->utr !== '' && $p['provider_txn_id'] !== null && $r->utr !== (string) $p['provider_txn_id']) {
            $problems['reference_differs'] = ['gateway_reference' => $r->utr, 'recorded_reference' => (string) $p['provider_txn_id']];
        }
        foreach ($problems as $kind => $facts) {
            self::record('conflicting_status', $base + ['key' => "p{$id}:{$kind}", 'details' => $mine + $facts + ['kind' => $kind]]);
        }
        self::resolveKeys(array_map(static fn(string $k): string => "conflicting_status:p{$id}:{$k}", array_values(array_diff(['webhook_not_confirmed', 'amount_differs', 'currency_differs', 'reference_differs'], array_keys($problems)))));
        return $problems ? 'conflict' : 'confirmed';
    }

    /**
     * After a webhook has been accepted: ask the gateway whether it agrees. Not for payments an admin confirmed by hand (the gateway may
     * legitimately not know those).
     *
     * @return string confirmed | conflict | not_confirmed | unreachable | skipped
     */
    public static function doubleCheck(int $paymentId, bool $final = false): string
    {
        $p = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if ($p === null || $p['status'] !== Status::PAY_SUCCESS || (string) $p['verified_via'] === 'admin') {
            return 'skipped';
        }
        $r = self::lookup($p);
        return $r === null ? 'unreachable' : self::compare($p, $r, $final);
    }

    /**
     * An admin asks the gateway about one payment right now. Anything unpaid is settled or closed by exactly the rules the automatic poll uses;
     * a payment already paid is compared with the gateway's answer; a flagged payment is never changed (a person confirms it by hand, audited).
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function recheck(int $paymentId, array $admin, ?Request $req = null): array
    {
        RateLimiter::enforce('payment-recheck', (string) $admin['id'], 30, 300, 'Too many re-checks in a short time. Wait a few minutes.');
        $p = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]) ?? throw new HttpException(404, 'Payment not found.');
        if ($p['gateway_id'] === null || Gateways::row((int) $p['gateway_id']) === null) {
            throw new HttpException(409, 'This payment has no payment gateway to ask.', 'no_gateway');
        }
        if (in_array($p['status'], [Status::PAY_REFUNDED, Status::PAY_PART_REFUNDED], true)) {
            throw new HttpException(409, 'A refunded payment is not re-checked.', 'not_allowed');
        }
        $r = self::lookup($p);
        if ($r === null) {
            throw new HttpException(502, 'The payment gateway could not be reached, so nothing was checked. Try again in a moment.', 'gateway_unreachable');
        }
        self::touch($paymentId);

        $action = 'none';
        $message = match ($r->status) {
            PaymentStatusResult::FAILED  => 'The gateway also reports this payment as failed.',
            PaymentStatusResult::EXPIRED => 'The gateway also reports this payment session as expired.',
            PaymentStatusResult::SUCCESS => 'The gateway reports this payment as paid.',
            default                      => 'The gateway still shows this payment as unpaid.',
        };
        $route = null;
        $orderId = (int) $p['order_id'];
        $status = (string) $p['status'];
        if (in_array($status, [Status::PAY_PENDING, Status::PAY_CREATING, Status::PAY_EXPIRED, Status::PAY_FAILED], true)) {
            if ($r->status === PaymentStatusResult::SUCCESS) {
                $s = Settlement::settle($paymentId, ['source' => 'poll', 'amount_paise' => $r->amountPaise, 'currency' => $r->currency, 'utr' => $r->utr, 'method' => $r->method]);
                $route = $s['route'];
                [$action, $message] = match ($s['result']) {
                    Settlement::SETTLED  => ['settled', 'The gateway confirms the payment. The order is now paid.'],
                    Settlement::MISMATCH => ['flagged', $s['message']],
                    default              => ['none', $s['message']],
                };
            } elseif ($r->status === PaymentStatusResult::FAILED && $status !== Status::PAY_FAILED) {
                PaymentService::fail($paymentId, 'The payment failed at the gateway.');
                [$action, $message] = ['failed', 'The gateway reports that the payment failed. It is now marked failed.'];
            } elseif ($r->status === PaymentStatusResult::EXPIRED && $status !== Status::PAY_EXPIRED) {
                PaymentService::expire($paymentId, true);
                [$action, $message] = ['expired', 'The gateway reports that the payment session expired. It is now marked expired.'];
            }
        } elseif ($status === Status::PAY_MISMATCH) {
            $message = 'This payment stays flagged: a re-check never overrides a flagged payment. Check the bank statement, then confirm it by hand from the order if the money really arrived.';
        } elseif ($status === Status::PAY_SUCCESS) {
            if ((string) $p['verified_via'] === 'admin') {
                $message = $r->status === PaymentStatusResult::SUCCESS
                    ? 'This payment was confirmed by hand and the gateway agrees that it is paid.'
                    : 'This payment was confirmed by hand. The gateway does not list it as paid, which is expected when it was verified from the bank statement.';
            } else {
                $verdict = self::compare($p, $r, true);
                [$action, $message] = match ($verdict) {
                    'confirmed' => ['confirmed', 'The gateway confirms this payment: same status, amount and reference.'],
                    'conflict'  => ['flagged', 'The gateway’s answer differs from what was recorded. A flag was added with the details.'],
                    default     => ['flagged', 'The gateway does not show this payment as paid. A flag was added with the details.'],
                };
            }
        }
        if ($route === 'AUTO') {
            Fulfilment::submit($orderId);
        }
        self::flush();
        Audit::log('payment.rechecked', 'payment', $paymentId, ['gateway_status' => $r->status, 'outcome' => $action], $req, $admin);
        return [
            'action' => $action, 'message' => $message,
            'gateway' => ['status' => $r->status, 'amount_paise' => $r->amountPaise, 'reference' => $r->utr, 'method' => $r->method, 'currency' => $r->currency],
            'payment' => self::monitorRows('p.id = ?', [$paymentId])[0] ?? null,
        ];
    }

    // ── hooks from the webhook pipeline ──────────────────────────────────────

    /** A delivery failed signature verification: flag the gateway once there have been "threshold" of them within the hour. */
    public static function onInvalidSignature(int $gatewayId): void
    {
        try {
            $t = self::thresholds();
            $n = (int) Db::val('SELECT COUNT(*) FROM webhook_events WHERE gateway_id = ? AND signature_valid = 0 AND received_at >= ?', [$gatewayId, Clock::sql(Clock::now() - 3600)]);
            if ($n >= $t['invalid_signatures_per_hour']) {
                self::record('invalid_signature', [
                    'gateway_id' => $gatewayId, 'key' => 'g' . $gatewayId, 'severity' => $n >= 5 * $t['invalid_signatures_per_hour'] ? 'high' : 'medium',
                    'details' => ['rejected_last_hour' => $n, 'threshold' => $t['invalid_signatures_per_hour']],
                ]);
            }
        } catch (\Throwable $e) {
            Log::exception($e, 'Invalid-signature check failed');
        }
    }

    // ── cron ─────────────────────────────────────────────────────────────────

    /**
     * Part of the `payments` job. With a dry run nothing is written and the gateway is not asked: the counters say what a real run would do.
     *
     * @return array<string,int>
     */
    public static function scan(JobContext $c): array
    {
        $write = !$c->dryRun;
        $t = self::thresholds();
        $now = Clock::now();
        $out = ['flagged' => 0, 'resolved' => 0, 'rechecked' => 0, 'confirmed' => 0, 'unreachable' => 0];

        // 1. bursts of rejected signatures
        $bursts = [];
        foreach (Db::all('SELECT gateway_id, COUNT(*) AS n FROM webhook_events WHERE signature_valid = 0 AND gateway_id IS NOT NULL AND received_at >= ? GROUP BY gateway_id HAVING COUNT(*) >= ?', [Clock::sql($now - 3600), $t['invalid_signatures_per_hour']]) as $r) {
            $bursts[] = 'invalid_signature:g' . $r['gateway_id'];
            $out['flagged']++;
            if ($write) {
                self::record('invalid_signature', [
                    'gateway_id' => (int) $r['gateway_id'], 'key' => 'g' . $r['gateway_id'], 'severity' => (int) $r['n'] >= 5 * $t['invalid_signatures_per_hour'] ? 'high' : 'medium',
                    'details' => ['rejected_last_hour' => (int) $r['n'], 'threshold' => $t['invalid_signatures_per_hour']],
                ]);
            }
        }
        $out['resolved'] += self::resolveMissing('invalid_signature', $bursts, $write);

        // 2. one visitor, several payments, none completed
        $found = [];
        if (!$c->expired()) {
            $since = Clock::sql($now - $t['failed_window_hours'] * 3600);
            $rows = Db::all(
                "SELECT o.visitor_id, COUNT(*) AS n, MAX(p.id) AS payment_id, MAX(p.order_id) AS order_id FROM payments p JOIN orders o ON o.id = p.order_id "
                . "WHERE p.created_at >= ? AND p.status IN ('failed', 'expired') AND o.visitor_id IS NOT NULL "
                . "AND NOT EXISTS (SELECT 1 FROM orders o2 JOIN payments p2 ON p2.order_id = o2.id WHERE o2.visitor_id = o.visitor_id AND p2.created_at >= ? AND p2.status IN ('success', 'refunded', 'partially_refunded')) "
                . 'GROUP BY o.visitor_id HAVING COUNT(*) >= ? ORDER BY n DESC LIMIT ' . self::SCAN_PAGE,
                [$since, $since, $t['failed_attempts']]
            );
            foreach ($rows as $r) {
                $found[] = 'repeated_failures:v' . $r['visitor_id'];
                $out['flagged']++;
                if ($write) {
                    self::record('repeated_failures', [
                        'payment_id' => (int) $r['payment_id'], 'order_id' => (int) $r['order_id'], 'key' => 'v' . $r['visitor_id'],
                        'details' => ['unfinished_payments' => (int) $r['n'], 'window_hours' => $t['failed_window_hours']],
                    ]);
                }
            }
            if (count($rows) < self::SCAN_PAGE) {       // only a complete answer can say that a problem is gone
                $out['resolved'] += self::resolveMissing('repeated_failures', $found, $write);
            }
        }

        // 3. order and payment records that do not agree (the last 14 days)
        $found = [];
        $complete = [];
        if (!$c->expired()) {
            $since = Clock::sql($now - 14 * 86400);
            $page = ' LIMIT ' . self::SCAN_PAGE;
            $queries = [
                'paid_payment_unpaid_order' => ["SELECT p.id AS pid, p.order_id AS oid FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.status = 'success' AND o.status = 'PENDING_PAYMENT' AND p.paid_at >= ? AND p.paid_at < ?" . $page, [$since, Clock::sql($now - 60)]],
                'paid_order_without_payment' => ['SELECT o.id AS oid, (SELECT MAX(p.id) FROM payments p WHERE p.order_id = o.id) AS pid FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_at >= ? AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND p.status IN (\'success\', \'refunded\', \'partially_refunded\'))' . $page, [$since]],
                'amount_differs_from_order' => ['SELECT p.id AS pid, p.order_id AS oid FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.created_at >= ? AND p.amount_paise <> o.amount_paise' . $page, [$since]],
                'paid_amount_differs' => ["SELECT p.id AS pid, p.order_id AS oid FROM payments p WHERE p.status = 'success' AND p.paid_at >= ? AND p.paid_amount_paise IS NOT NULL AND p.paid_amount_paise <> p.amount_paise" . $page, [$since]],
            ];
            foreach ($queries as $kind => [$sql, $args]) {
                $rows = Db::all($sql, $args);
                if (count($rows) < self::SCAN_PAGE) {
                    $complete[] = $kind;
                }
                foreach ($rows as $r) {
                    $pid = $r['pid'] !== null ? (int) $r['pid'] : null;
                    $found[] = 'mapping_inconsistent:' . ($pid !== null ? 'p' . $pid : 'o' . $r['oid']) . ':' . $kind;
                    $out['flagged']++;
                    if ($write) {
                        self::record('mapping_inconsistent', [
                            'payment_id' => $pid, 'order_id' => (int) $r['oid'], 'key' => ($pid !== null ? 'p' . $pid : 'o' . $r['oid']) . ':' . $kind, 'details' => ['kind' => $kind],
                        ]);
                    }
                }
            }
        }
        // closed only for the kinds the scan could see completely, and never a flag the scan does not derive itself (e.g. recorded by the settlement)
        $out['resolved'] += self::resolveMissing('mapping_inconsistent', $found, $write, $complete);

        // 4. payments a webhook marked paid that the gateway has not been asked about since
        if ($t['webhook_double_check'] !== 'off' && !$c->expired()) {
            $due = Db::all(
                "SELECT * FROM payments WHERE status = 'success' AND verified_via = 'webhook' AND paid_at >= ? AND paid_at <= ? AND (last_polled_at IS NULL OR last_polled_at < paid_at) ORDER BY id LIMIT 10",
                [Clock::sql($now - 86400), Clock::sql($now - $t['confirm_grace_seconds'])]
            );
            foreach ($due as $p) {
                if ($c->expired()) {
                    break;
                }
                if (!$write) {
                    $out['rechecked']++;
                    continue;
                }
                $verdict = self::doubleCheck((int) $p['id'], true);
                if ($verdict === 'unreachable') {
                    $out['unreachable']++;
                    break;                       // the gateway is not answering: do not hammer it ten times
                }
                $out['rechecked']++;
                $out['confirmed'] += $verdict === 'confirmed' ? 1 : 0;
            }
        }

        // 5. an informational flag nobody needs to act on does not stay open forever
        if ($write) {
            $keys = Db::col("SELECT dedupe_key FROM payment_flags WHERE flag_type = 'late_payment' AND active_key IS NOT NULL AND last_seen_at < ?", [Clock::sql($now - 14 * 86400)]);
            $out['resolved'] += self::resolveKeys(array_map('strval', $keys));
        }
        if ($write) {
            self::flush();
        }
        return $out;
    }

    // ── what the admin sees ──────────────────────────────────────────────────

    /** @return array{high:int,medium:int,low:int,attention:int} live flags; "attention" = high + medium (what the sidebar badge shows) */
    public static function openCounts(): array
    {
        $out = ['high' => 0, 'medium' => 0, 'low' => 0, 'attention' => 0];
        foreach (Db::all('SELECT severity, COUNT(*) AS n FROM payment_flags WHERE active_key IS NOT NULL GROUP BY severity') as $r) {
            $out[$r['severity']] = (int) $r['n'];
        }
        $out['attention'] = $out['high'] + $out['medium'];
        return $out;
    }

    /**
     * The payment-checks overview: numbers that come straight from the tables.
     *
     * @return array<string,mixed>
     */
    public static function summary(): array
    {
        $now = Clock::now();
        $byState = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM payments WHERE created_at >= ? GROUP BY status', [Clock::sql($now - 30 * 86400)]) as $r) {
            $state = Status::specPayment((string) $r['status']);
            $byState[$state] = ($byState[$state] ?? 0) + (int) $r['n'];
        }
        $wh = ['received' => 0, 'processed' => 0, 'rejected' => 0, 'errors' => 0, 'repeats' => 0, 'last_accepted_at' => null];
        foreach (Db::all("SELECT status, COUNT(*) AS n, SUM(CASE WHEN attempts > 1 THEN attempts - 1 ELSE 0 END) AS reps, MAX(received_at) AS last_at FROM webhook_events WHERE received_at >= ? GROUP BY status", [Clock::sql($now - 86400)]) as $r) {
            $wh['received'] += (int) $r['n'];
            $wh['repeats'] += (int) $r['reps'];
            match ($r['status']) {
                'processed', 'duplicate' => $wh['processed'] += (int) $r['n'],
                'invalid' => $wh['rejected'] += (int) $r['n'],
                'error', 'mismatch' => $wh['errors'] += (int) $r['n'],
                default => null,
            };
            if (in_array($r['status'], ['processed', 'ignored', 'mismatch'], true) && ($wh['last_accepted_at'] === null || $r['last_at'] > $wh['last_accepted_at'])) {
                $wh['last_accepted_at'] = $r['last_at'];
            }
        }
        $wh['last_accepted_at'] = Clock::iso($wh['last_accepted_at']);
        return [
            'by_state' => $byState, 'open_flags' => self::openCounts(),
            'needs_review' => (int) Db::val("SELECT COUNT(*) FROM payments WHERE status = 'mismatch'"),
            'webhooks_24h' => $wh, 'double_check' => self::thresholds()['webhook_double_check'],
        ];
    }

    private const STATE_FILTER = [
        'CREATED' => ['creating'], 'PENDING' => ['pending'], 'PAID' => ['success'], 'FAILED' => ['failed'], 'EXPIRED' => ['expired'],
        'REVIEW_REQUIRED' => ['mismatch'], 'REFUNDED' => ['refunded', 'partially_refunded'],
    ];

    /**
     * Payments with everything the monitoring screen shows.
     *
     * @param array{state?:string,flag?:string,q?:string,page?:int} $q state: CREATED | PENDING | PAID | FAILED | EXPIRED | REVIEW_REQUIRED | REFUNDED | FLAGGED
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per:int}
     */
    public static function monitor(array $q): array
    {
        $where = ['1 = 1'];
        $args = [];
        $state = strtoupper((string) ($q['state'] ?? ''));
        if ($state === 'FLAGGED') {
            $where[] = 'EXISTS (SELECT 1 FROM payment_flags f WHERE f.payment_id = p.id AND f.active_key IS NOT NULL)';
        } elseif ($state !== '') {
            if (!isset(self::STATE_FILTER[$state])) {
                throw new HttpException(422, 'Unknown payment state.', 'invalid');
            }
            $where[] = 'p.status IN (' . Db::in(self::STATE_FILTER[$state]) . ')';
            array_push($args, ...self::STATE_FILTER[$state]);
        }
        $flag = (string) ($q['flag'] ?? '');
        if ($flag !== '') {
            if (!isset(self::TYPES[$flag])) {
                throw new HttpException(422, 'Unknown flag type.', 'invalid');
            }
            $where[] = 'EXISTS (SELECT 1 FROM payment_flags f WHERE f.payment_id = p.id AND f.active_key IS NOT NULL AND f.flag_type = ?)';
            $args[] = $flag;
        }
        $search = trim((string) ($q['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(o.public_id LIKE ? OR p.gateway_order_id = ? OR p.provider_txn_id = ?)';
            array_push($args, '%' . addcslashes(mb_substr($search, 0, 60), '%_\\') . '%', $search, $search);
        }
        $w = implode(' AND ', $where);
        $per = 25;
        $total = (int) Db::val("SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE {$w}", $args);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) ($q['page'] ?? 1)), $pages);
        return ['rows' => self::monitorRows($w . ' ORDER BY p.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
    }

    /** @param list<mixed> $args @return list<array<string,mixed>> */
    private static function monitorRows(string $whereAndTail, array $args): array
    {
        $rows = Db::all(
            'SELECT p.*, o.public_id, o.status AS order_status, o.amount_paise AS order_amount_paise, g.name AS gateway_name '
            . "FROM payments p JOIN orders o ON o.id = p.order_id LEFT JOIN payment_gateways g ON g.id = p.gateway_id WHERE {$whereAndTail}",
            $args
        );
        if (!$rows) {
            return [];
        }
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $in = Db::in($ids);
        $flags = [];
        foreach (Db::all("SELECT id, payment_id, flag_type, severity, occurrences FROM payment_flags WHERE active_key IS NOT NULL AND payment_id IN ({$in}) ORDER BY FIELD(severity, 'high', 'medium', 'low'), id", $ids) as $f) {
            $flags[(int) $f['payment_id']][] = ['id' => (int) $f['id'], 'type' => $f['flag_type'], 'label' => self::TYPES[$f['flag_type']][1] ?? $f['flag_type'], 'severity' => $f['severity'], 'occurrences' => (int) $f['occurrences']];
        }
        $hooks = [];
        foreach (Db::all("SELECT w.payment_id, w.status, w.signature_valid, w.attempts, w.last_attempt_at, w.received_at, w.error FROM webhook_events w JOIN (SELECT payment_id, MAX(id) AS mid FROM webhook_events WHERE payment_id IN ({$in}) GROUP BY payment_id) m ON m.mid = w.id", $ids) as $w) {
            $hooks[(int) $w['payment_id']] = [
                'status' => $w['status'], 'signature_valid' => (bool) $w['signature_valid'], 'deliveries' => (int) $w['attempts'], 'repeats_ignored' => max(0, (int) $w['attempts'] - 1),
                'last_repeat_at' => Clock::iso($w['last_attempt_at']), 'received_at' => Clock::iso($w['received_at']), 'note' => $w['error'],
            ];
        }
        return array_map(static function (array $p) use ($flags, $hooks): array {
            $id = (int) $p['id'];
            $status = (string) $p['status'];
            return [
                'id' => $id, 'order_id' => (int) $p['order_id'], 'order' => $p['public_id'], 'order_status' => $p['order_status'], 'gateway' => $p['gateway_name'], 'provider' => $p['provider'],
                'gateway_reference' => $p['gateway_order_id'], 'bank_reference' => $p['provider_txn_id'], 'method' => $p['gateway_method'],
                'currency' => $p['currency'], 'expected_paise' => (int) $p['amount_paise'], 'verified_paise' => $p['paid_amount_paise'] !== null ? (int) $p['paid_amount_paise'] : null,
                'order_amount_paise' => (int) $p['order_amount_paise'], 'refunded_paise' => (int) $p['refunded_paise'],
                'status' => $status, 'state' => Status::specPayment($status), 'verification_source' => $p['verified_via'], 'late' => (bool) $p['late'], 'reason' => $p['failure_reason'],
                'created_at' => Clock::iso($p['created_at']), 'verified_at' => Clock::iso($p['paid_at']), 'expires_at' => Clock::iso($p['expires_at']),
                'last_checked_at' => Clock::iso($p['last_polled_at']), 'checks' => (int) $p['poll_count'],
                'flags' => $flags[$id] ?? [], 'webhook' => $hooks[$id] ?? null,
                'can_recheck' => $p['gateway_id'] !== null && !in_array($status, [Status::PAY_REFUNDED, Status::PAY_PART_REFUNDED], true),
            ];
        }, $rows);
    }

    /**
     * Flags, newest problem first.
     *
     * @param array{status?:string,severity?:string,type?:string,page?:int} $f status: open (default) | closed | all
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per:int}
     */
    public static function flags(array $f = []): array
    {
        $where = [];
        $args = [];
        $status = (string) ($f['status'] ?? 'open');
        if ($status === 'open') {
            $where[] = 'f.active_key IS NOT NULL';
        } elseif ($status === 'closed') {
            $where[] = 'f.active_key IS NULL';
        } elseif ($status !== 'all') {
            throw new HttpException(422, 'Unknown flag status.', 'invalid');
        }
        if (($f['severity'] ?? '') !== '') {
            if (!in_array($f['severity'], self::SEVERITIES, true)) {
                throw new HttpException(422, 'Unknown severity.', 'invalid');
            }
            $where[] = 'f.severity = ?';
            $args[] = $f['severity'];
        }
        if (($f['type'] ?? '') !== '') {
            if (!isset(self::TYPES[$f['type']])) {
                throw new HttpException(422, 'Unknown flag type.', 'invalid');
            }
            $where[] = 'f.flag_type = ?';
            $args[] = $f['type'];
        }
        $w = $where ? implode(' AND ', $where) : '1 = 1';
        $per = 25;
        $total = (int) Db::val("SELECT COUNT(*) FROM payment_flags f WHERE {$w}", $args);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = self::flagQuery($w . " ORDER BY (f.active_key IS NULL), FIELD(f.severity, 'high', 'medium', 'low'), f.last_seen_at DESC, f.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $args);
        return ['rows' => array_map([self::class, 'flagRow'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
    }

    /** @param list<mixed> $args @return list<array<string,mixed>> */
    private static function flagQuery(string $whereAndTail, array $args): array
    {
        return Db::all(
            'SELECT f.*, o.public_id, p.gateway_order_id, a.username AS reviewer FROM payment_flags f LEFT JOIN orders o ON o.id = f.order_id '
            . "LEFT JOIN payments p ON p.id = f.payment_id LEFT JOIN admins a ON a.id = f.reviewed_by WHERE {$whereAndTail}",
            $args
        );
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private static function flagRow(array $f): array
    {
        $details = $f['details_json'] !== null ? json_decode((string) $f['details_json'], true) : null;
        return [
            'id' => (int) $f['id'], 'type' => $f['flag_type'], 'label' => self::TYPES[$f['flag_type']][1] ?? $f['flag_type'], 'advice' => self::TYPES[$f['flag_type']][2] ?? '',
            'severity' => $f['severity'], 'status' => $f['status'], 'open' => $f['active_key'] !== null, 'occurrences' => (int) $f['occurrences'],
            'payment_id' => $f['payment_id'] !== null ? (int) $f['payment_id'] : null, 'order_id' => $f['order_id'] !== null ? (int) $f['order_id'] : null,
            'order' => $f['public_id'], 'gateway_reference' => $f['gateway_order_id'],
            'first_seen_at' => Clock::iso($f['first_seen_at']), 'last_seen_at' => Clock::iso($f['last_seen_at']),
            'details' => is_array($details) ? $details : [], 'reviewed_by' => $f['reviewer'], 'reviewed_at' => Clock::iso($f['reviewed_at']), 'note' => $f['review_note'],
        ];
    }
}
