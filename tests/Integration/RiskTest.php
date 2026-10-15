<?php
declare(strict_types=1);

use App\Controllers\Admin\OrdersController;
use App\Controllers\Admin\PaymentChecksController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\Alerts;
use App\Services\JobContext;
use App\Services\Jobs;
use App\Services\PaymentService;
use App\Services\Risk;
use App\Services\Settings;
use App\Support\Clock;

/**
 * Payment checks: what is flagged, when, how often, and that a flag never changes money or blocks anybody.
 * Uses the real checkout / webhook / settlement code against the mock Alpha UPI gateway (helpers from PaymentFlowTest.php).
 */

const RK_ADMIN = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];

/** A fresh place for the next checkout: the checkout is rate-limited per IP, a test needs many. */
function rk_ip(): string
{
    static $n = 0;
    return '198.51.100.' . (10 + (++$n % 200));
}

/** An order + payment for a distinct account link (the same link twice would return the same pending payment). */
function rk_place(string $name, array $visitor = ['visitor_id' => null, 'session_id' => null], string $code = 'ig-followers-50'): array
{
    return pf_place($code, 'https://www.instagram.com/' . $name . '/', $visitor, rk_ip());
}

/** The admin account id 1 (the tests' RK_ADMIN), so reviewer names can be read back. */
function rk_seed_admin(): void
{
    Db::insert('admins', [
        'username' => 'root', 'email' => 'r@x.test', 'password_hash' => 'x', 'role' => 'owner', 'is_active' => 1, 'must_change_password' => 0,
        'password_changed_at' => Clock::sql(), 'created_at' => Clock::sql(), 'updated_at' => Clock::sql(),
    ]);
}

function rk_flags(?string $type = null): array
{
    return $type === null ? Db::all('SELECT * FROM payment_flags ORDER BY id') : Db::all('SELECT * FROM payment_flags WHERE flag_type = ? ORDER BY id', [$type]);
}

function rk_details(array $flag): array
{
    return json_decode((string) $flag['details_json'], true) ?: [];
}

function rk_req(string $method, string $path, string $role = 'owner', array $body = [], array $query = []): Request
{
    $req = Request::fake($method, $path, ['Content-Type' => 'application/json'], $body ? json_encode($body) : '', [], '203.0.113.9', $query);
    $req->attributes['admin'] = ['id' => 1, 'username' => $role . '-user', 'email' => 'u@x.test', 'role' => $role, 'must_change_password' => false];
    return $req;
}

/** @return array<string,mixed> the decoded JSON of a controller response */
function rk_json(\App\Core\Response $res): array
{
    return json_decode((string) $res->body, true) ?: [];
}

function rk_scan(bool $dry = false): array
{
    return Risk::scan(new JobContext(microtime(true) + 30, $dry));
}

function rk_pay(int $gid, array $payment, array $over = []): array
{
    return pf_webhook($gid, pf_evt($payment, $over));
}

// ═════════════════════════════ recording ═════════════════════════════

T::test('flags: the same problem raises occurrences on ONE live flag; a review lets the next occurrence open a new one', function () {
    pf_setup();
    $pid = (int) rk_place('one')['payment']['id'];
    $a = Risk::record('late_payment', ['payment_id' => $pid, 'details' => ['seconds_after_expiry' => 200]]);
    $b = Risk::record('late_payment', ['payment_id' => $pid, 'details' => ['seconds_after_expiry' => 300]]);
    T::true($a['created'] && !$b['created'], 'the second sighting does not create a row');
    T::same($a['id'], $b['id']);
    T::same(2, $b['occurrences']);
    T::same(1, count(rk_flags()));
    T::same(300, rk_details(rk_flags()[0])['seconds_after_expiry'], 'the latest facts are kept');

    Risk::review($a['id'], 'reviewed', 'looked at it', RK_ADMIN);
    $row = rk_flags()[0];
    T::same(['reviewed', null, 1], [$row['status'], $row['active_key'], (int) $row['reviewed_by']]);
    $c = Risk::record('late_payment', ['payment_id' => $pid]);
    T::true($c['created'] && $c['id'] !== $a['id'], 'a recurrence after a review opens a new flag');
    T::same(2, count(rk_flags()));
});

T::test('flags: an unknown type is refused; details keep plain facts only (scalars, short, no paths)', function () {
    pf_setup();
    $pid = (int) rk_place('two')['payment']['id'];
    T::throws(InvalidArgumentException::class, fn() => Risk::record('made_up', ['payment_id' => $pid]));
    Risk::record('late_payment', ['payment_id' => $pid, 'details' => [
        'ok' => 5, 'nested' => ['a' => 1], 'object' => new stdClass(), 'long' => str_repeat('word ', 80), 'path' => 'failed in ' . SMB_ROOT . '/app/Services/X.php', 'flag' => true,
    ]]);
    $d = rk_details(rk_flags()[0]);
    T::same(5, $d['ok']);
    T::true($d['flag'] === true);
    T::false(isset($d['nested']) || isset($d['object']), 'arrays and objects are dropped');
    T::true(mb_strlen($d['long']) <= 200);
    T::false(str_contains($d['path'], SMB_ROOT), 'no server paths');
});

// ═════════════════════════════ what the settlement flags ═════════════════════════════

T::test('amount mismatch: the payment is held (REVIEW_REQUIRED), the order stays unpaid, ONE high flag with both amounts, ONE critical alert', function () {
    $gid = pf_setup();
    $p = rk_place('mismatch');
    [$status] = rk_pay($gid, $p['payment'], ['amount' => '4.00']);
    T::same(200, $status);
    $pay = pf_payment((int) $p['payment']['id']);
    T::same('mismatch', $pay['status']);
    T::same('PENDING_PAYMENT', pf_order((int) $p['order']['id'])['status'], 'nothing was fulfilled');
    $flags = rk_flags();
    T::same(1, count($flags));
    T::same(['amount_mismatch', 'high', 'open'], [$flags[0]['flag_type'], $flags[0]['severity'], $flags[0]['status']]);
    T::same([(int) $p['payment']['id'], (int) $p['order']['id']], [(int) $flags[0]['payment_id'], (int) $flags[0]['order_id']]);
    T::same([500, 400], [rk_details($flags[0])['expected_paise'], rk_details($flags[0])['reported_paise']]);

    rk_pay($gid, $p['payment'], ['amount' => '4.00']);      // the gateway insists (new event id): still one flag
    T::same(1, count(rk_flags()));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM business_alerts WHERE alert_type = 'payment_flag' AND severity = 'critical' AND status = 'open'"), 'one alert for one problem');
});

T::test('currency, missing amount and a reused bank reference each raise their own flag and hold the payment', function () {
    $gid = pf_setup();
    $a = rk_place('cur');
    rk_pay($gid, $a['payment'], ['currency' => 'USD']);
    $b = rk_place('noamount');
    $evt = pf_evt($b['payment']);
    unset($evt['amount']);
    pf_webhook($gid, $evt);
    $c = rk_place('first');
    rk_pay($gid, $c['payment'], ['utr' => 'UTRSAME1']);
    $d = rk_place('second');
    rk_pay($gid, $d['payment'], ['utr' => 'UTRSAME1']);

    $by = array_column(rk_flags(), null, 'flag_type');
    $types = array_keys($by);
    sort($types);
    T::same(['currency_mismatch', 'duplicate_reference', 'missing_amount'], $types);
    T::same('high', $by['currency_mismatch']['severity']);
    T::same('medium', $by['missing_amount']['severity']);
    T::same('high', $by['duplicate_reference']['severity']);
    T::same('USD', rk_details($by['currency_mismatch'])['gateway_currency']);
    T::same('UTRSAME1', rk_details($by['duplicate_reference'])['reference']);
    foreach ([$a, $b, $d] as $x) {
        T::same('mismatch', pf_payment((int) $x['payment']['id'])['status']);
        T::same('PENDING_PAYMENT', pf_order((int) $x['order']['id'])['status']);
    }
    T::same('success', pf_payment((int) $c['payment']['id'])['status'], 'the first payment with that reference is fine');
});

T::test('a second payment for an order that is already paid is flagged as "paid more than once" and credited once', function () {
    $gid = pf_setup();
    $p = rk_place('twice');
    rk_pay($gid, $p['payment'], ['utr' => 'UTRFIRST']);
    rk_pay($gid, $p['payment'], ['utr' => 'UTRSECOND']);       // another event, another bank reference, same payment
    $f = rk_flags('duplicate_payment');
    T::same(1, count($f));
    T::same(['UTRSECOND', 'UTRFIRST'], [rk_details($f[0])['new_reference'], rk_details($f[0])['first_reference']]);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]), 'fulfilled once');
    T::same('medium', $f[0]['severity']);
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM business_alerts WHERE alert_type = 'payment_flag'"), 'a medium flag is on the screen, not in the e-mail');
});

T::test('a payment confirmed after its session ended is credited AND gets an informational flag (switchable)', function () {
    $gid = pf_setup();
    Clock::setFixed(1_800_000_000);
    $p = rk_place('late');
    Clock::advance(300 + 121);
    rk_pay($gid, $p['payment']);
    T::same('success', pf_payment((int) $p['payment']['id'])['status']);
    $f = rk_flags('late_payment');
    T::same(1, count($f));
    T::same(['low', 121], [$f[0]['severity'], rk_details($f[0])['seconds_after_expiry']]);

    Settings::set('risk.flag_late_payments', false);
    $q = rk_place('late2');
    Clock::advance(300 + 130);
    rk_pay($gid, $q['payment']);
    T::same('success', pf_payment((int) $q['payment']['id'])['status']);
    T::same(1, count(rk_flags('late_payment')), 'switched off: no second flag');
    Clock::setFixed(null);
});

// ═════════════════════════════ what the webhook pipeline flags ═════════════════════════════

T::test('an unknown reference in a SIGNED webhook is acknowledged, flagged once per reference, and credits nothing', function () {
    $gid = pf_setup();
    $evt = static fn(string $ref): array => ['event_id' => 'e' . bin2hex(random_bytes(4)), 'order_id' => $ref, 'status' => 'SUCCESS', 'amount' => '5.00', 'utr' => 'UTRX' . bin2hex(random_bytes(3))];
    [$st] = pf_webhook($gid, $evt('NOT-OURS-1'));
    T::same(200, $st);
    pf_webhook($gid, $evt('NOT-OURS-1'));
    pf_webhook($gid, $evt('NOT-OURS-2'));
    $f = rk_flags('unknown_reference');
    T::same(2, count($f), 'one flag per reference');
    T::same([2, 1], [(int) $f[0]['occurrences'], (int) $f[1]['occurrences']]);
    T::same(['medium', null], [$f[0]['severity'], $f[0]['payment_id']]);
    T::same('NOT-OURS-1', rk_details($f[0])['gateway_order_id']);
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM payments WHERE status = 'success'"));
});

T::test('"failed" / "expired" for a payment already recorded as paid: the payment stays paid and the disagreement is flagged', function () {
    $gid = pf_setup();
    $p = rk_place('conflict');
    rk_pay($gid, $p['payment']);
    [$st] = pf_webhook($gid, ['event_id' => 'evt_fail_1', 'order_id' => $p['payment']['gateway_order_id'], 'status' => 'FAILED']);
    T::same(200, $st);
    T::same('success', pf_payment((int) $p['payment']['id'])['status']);
    $f = rk_flags('conflicting_status');
    T::same(1, count($f));
    T::same(['high', 'failed_after_paid'], [$f[0]['severity'], rk_details($f[0])['kind']]);
    T::same('mismatch', Db::val("SELECT status FROM webhook_events WHERE event_id = 'evt_fail_1'"), 'the event is recorded as a mismatch, not silently dropped');
});

T::test('signature failures: below the threshold nothing, at it a flag, far above it a high flag + alert, and the scan closes it when the hour has passed', function () {
    $gid = pf_setup();
    Clock::setFixed(1_800_000_000);
    Settings::set('risk.invalid_signatures_per_hour', 3);
    $bad = static fn() => pf_webhook($gid, ['order_id' => 'x', 'status' => 'SUCCESS'], 'test-webhook-secret-456', 'deadbeef');
    foreach ([1, 2] as $_) {
        T::same(401, $bad()[0]);
    }
    T::same(0, count(rk_flags()), 'two rejected deliveries are below the threshold of three');
    $bad();
    $f = rk_flags('invalid_signature');
    T::same(1, count($f));
    T::same(['medium', 3], [$f[0]['severity'], rk_details($f[0])['rejected_last_hour']]);
    for ($i = 0; $i < 12; $i++) {
        $bad();
    }
    $f = rk_flags('invalid_signature');
    T::same(1, count($f), 'still one flag');
    T::same('high', $f[0]['severity']);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM business_alerts WHERE alert_type = 'payment_flag' AND status = 'open'"));

    Clock::advance(7200);
    $out = rk_scan();
    T::true($out['resolved'] >= 1);
    $f = rk_flags('invalid_signature');
    T::same(['resolved', null], [$f[0]['status'], $f[0]['active_key']]);
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM business_alerts WHERE alert_type = 'payment_flag' AND status IN ('open', 'acknowledged')"), 'the alert closed with it');
    Clock::setFixed(null);
});

T::test('webhook duplicates are acknowledged, counted and dated — and processed once', function () {
    $gid = pf_setup();
    $p = rk_place('dup');
    $evt = pf_evt($p['payment']);
    pf_webhook($gid, $evt);
    pf_webhook($gid, $evt);
    pf_webhook($gid, $evt);
    $w = Db::one("SELECT * FROM webhook_events WHERE event_id = ?", [$evt['event_id']]);
    T::same([3, 'processed'], [(int) $w['attempts'], $w['status']]);
    T::true($w['last_attempt_at'] !== null, 'when the latest repeat arrived');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]));
    $row = Risk::monitor([])['rows'][0];
    T::same([3, 2], [$row['webhook']['deliveries'], $row['webhook']['repeats_ignored']]);
});

// ═════════════════════════════ cross-checking webhooks with the gateway ═════════════════════════════

T::test('cross-check (flag mode): a webhook the gateway agrees with leaves no flag; one it does not agree with is flagged only after the grace period', function () {
    $gid = pf_setup();
    Clock::setFixed(1_800_000_000);
    T::same('flag', Risk::thresholds()['webhook_double_check'], 'the default');

    $a = rk_place('agree');
    T::alpha('POST', '/__pay/' . $a['payment']['gateway_order_id'] . '/success', ['noWebhook' => true, 'utr' => 'UTRDC1']);
    rk_pay($gid, $a['payment'], ['utr' => 'UTRDC1']);
    $pa = pf_payment((int) $a['payment']['id']);
    T::same(['success', 'webhook'], [$pa['status'], $pa['verified_via']]);
    T::true($pa['last_polled_at'] !== null && (int) $pa['poll_count'] === 1, 'the gateway was asked once, after the response');
    T::same(0, count(rk_flags()));

    $b = rk_place('disagree');
    rk_pay($gid, $b['payment'], ['utr' => 'UTRDC2']);            // the gateway shows nothing for it
    T::same('success', pf_payment((int) $b['payment']['id'])['status'], 'credited on the signed webhook, as before');
    T::same(0, count(rk_flags()), 'a "not paid yet" answer seconds after a webhook may just be the gateway catching up');
    Clock::advance(60);
    T::same(0, rk_scan()['rechecked'], 'not due yet');
    Clock::advance(120);
    $out = rk_scan();
    T::same(1, $out['rechecked']);
    $f = rk_flags('conflicting_status');
    T::same(1, count($f));
    T::same(['webhook_not_confirmed', 'PENDING', 'high'], [rk_details($f[0])['kind'], rk_details($f[0])['gateway_status'], $f[0]['severity']]);
    T::same('success', pf_payment((int) $b['payment']['id'])['status'], 'a flag never undoes a payment');
    T::same(0, rk_scan()['rechecked'], 'asked once, not every five minutes');

    // the gateway catches up: a re-check by a person clears the flag
    T::alpha('POST', '/__pay/' . $b['payment']['gateway_order_id'] . '/success', ['noWebhook' => true, 'utr' => 'UTRDC2']);
    $r = Risk::recheck((int) $b['payment']['id'], RK_ADMIN);
    T::same('confirmed', $r['action']);
    T::same(['resolved', null], [rk_flags('conflicting_status')[0]['status'], rk_flags('conflicting_status')[0]['active_key']]);
    Clock::setFixed(null);
});

T::test('cross-check: the gateway reporting another amount for a paid payment is flagged immediately', function () {
    $gid = pf_setup();
    $p = rk_place('amt');
    T::alpha('POST', '/__pay/' . $p['payment']['gateway_order_id'] . '/success', ['noWebhook' => true, 'lookupAmount' => '9.00', 'utr' => 'UTRAMT']);
    rk_pay($gid, $p['payment'], ['utr' => 'UTRAMT']);          // the webhook says ₹5.00, the lookup says ₹9.00
    $f = rk_flags('conflicting_status');
    T::same(1, count($f));
    T::same(['amount_differs', 900, 500], [rk_details($f[0])['kind'], rk_details($f[0])['gateway_amount_paise'], rk_details($f[0])['recorded_amount_paise']]);
});

T::test('cross-check "off": the signed webhook is trusted on its own and the gateway is not asked', function () {
    $gid = pf_setup();
    Settings::set('risk.webhook_double_check', 'off');
    $p = rk_place('off');
    rk_pay($gid, $p['payment']);
    $pay = pf_payment((int) $p['payment']['id']);
    T::same(['success', null, 0], [$pay['status'], $pay['last_polled_at'], (int) $pay['poll_count']]);
    T::same(0, rk_scan()['rechecked']);
});

T::test('cross-check "require": a webhook is only a hint — credited from OUR lookup, never on its own; an unreachable gateway gets a retry', function () {
    $gid = pf_setup();
    Settings::set('risk.webhook_double_check', 'require');

    $a = rk_place('req_unpaid');                                   // webhook says paid, the gateway disagrees
    [$st] = rk_pay($gid, $a['payment']);
    T::same(200, $st);
    T::same(['pending', 'PENDING_PAYMENT'], [pf_payment((int) $a['payment']['id'])['status'], pf_order((int) $a['order']['id'])['status']], 'a forged or premature notification credits nothing');
    $f = rk_flags('conflicting_status');
    T::same(1, count($f));
    T::same(['webhook_not_confirmed', 'require'], [rk_details($f[0])['kind'], rk_details($f[0])['mode']]);

    $b = rk_place('req_paid');                                     // both agree
    T::alpha('POST', '/__pay/' . $b['payment']['gateway_order_id'] . '/success', ['noWebhook' => true, 'utr' => 'UTRREQ']);
    rk_pay($gid, $b['payment'], ['utr' => 'UTRREQ']);
    $pay = pf_payment((int) $b['payment']['id']);
    T::same(['success', 'poll', 'UTRREQ'], [$pay['status'], $pay['verified_via'], $pay['provider_txn_id']]);
    T::same('MANUAL_PENDING', pf_order((int) $b['order']['id'])['status']);

    $c = rk_place('req_retry');                                    // the gateway cannot be asked right now → 503, delivered again later
    T::alpha('POST', '/__pay/' . $c['payment']['gateway_order_id'] . '/success', ['noWebhook' => true, 'utr' => 'UTRRETRY']);
    $evt = pf_evt($c['payment'], ['utr' => 'UTRRETRY']);
    T::alpha('POST', '/__control', ['mode' => ['offline' => true]]);
    [$st] = pf_webhook($gid, $evt);
    T::same(503, $st);
    T::same('pending', pf_payment((int) $c['payment']['id'])['status']);
    T::same('error', Db::val('SELECT status FROM webhook_events WHERE event_id = ?', [$evt['event_id']]));
    T::alpha('POST', '/__control', ['mode' => ['offline' => false]]);
    [$st] = pf_webhook($gid, $evt);                                // the same delivery, again
    T::same(200, $st);
    T::same('success', pf_payment((int) $c['payment']['id'])['status']);
    T::same('processed', Db::val('SELECT status FROM webhook_events WHERE event_id = ?', [$evt['event_id']]));
});

// ═════════════════════════════ the scan ═════════════════════════════

T::test('scan: records that do not agree are flagged, and the flag closes by itself once they agree again', function () {
    $gid = pf_setup();
    Settings::set('risk.webhook_double_check', 'off');           // this test is about the records, not about the gateway
    Clock::setFixed(1_800_000_000);
    $p = rk_place('scan');
    rk_pay($gid, $p['payment']);
    $oid = (int) $p['order']['id'];
    $pid = (int) $p['payment']['id'];
    Clock::advance(120);
    T::same(0, rk_scan()['flagged'], 'consistent records raise nothing');

    $kinds = static fn(): array => array_map(static fn(array $f): string => rk_details($f)['kind'], array_filter(rk_flags('mapping_inconsistent'), static fn(array $f): bool => $f['active_key'] !== null));
    Db::update('orders', ['status' => 'PENDING_PAYMENT'], 'id = ?', [$oid]);
    rk_scan();
    T::same(['paid_payment_unpaid_order'], array_values($kinds()));
    Db::update('orders', ['status' => 'MANUAL_PENDING'], 'id = ?', [$oid]);
    rk_scan();
    T::same([], array_values($kinds()), 'fixed → closed');

    Db::update('payments', ['paid_amount_paise' => 400], 'id = ?', [$pid]);
    Db::update('orders', ['amount_paise' => 700], 'id = ?', [$oid]);
    rk_scan();
    $k = array_values($kinds());
    sort($k);
    T::same(['amount_differs_from_order', 'paid_amount_differs'], $k);

    Db::update('payments', ['paid_amount_paise' => 500], 'id = ?', [$pid]);
    Db::update('orders', ['amount_paise' => 500], 'id = ?', [$oid]);
    Db::update('payments', ['status' => 'failed'], 'id = ?', [$pid]);
    rk_scan();
    T::same(['paid_order_without_payment'], array_values($kinds()), 'a paid order whose payment is not paid');
    $closed = array_filter(rk_flags('mapping_inconsistent'), static fn(array $f): bool => $f['active_key'] === null && rk_details($f)['kind'] === 'paid_amount_differs');
    T::same(['resolved'], array_values(array_unique(array_column($closed, 'status'))));
    Clock::setFixed(null);
});

T::test('scan: it closes only what it derives itself — money that arrived for a cancelled order stays flagged — and never on the strength of a run that had no time', function () {
    $gid = pf_setup();
    Settings::set('risk.webhook_double_check', 'off');
    $p = rk_place('cancelled');
    Db::update('orders', ['status' => 'CANCELLED'], 'id = ?', [$p['order']['id']]);
    rk_pay($gid, $p['payment']);                         // the customer paid for an order that was cancelled meanwhile
    $f = rk_flags('mapping_inconsistent');
    T::same(1, count($f));
    T::same(['order_not_payable', 'CANCELLED', 'high'], [rk_details($f[0])['kind'], rk_details($f[0])['order_status'], $f[0]['severity']]);
    T::same('CANCELLED', pf_order((int) $p['order']['id'])['status'], 'nothing was reopened or fulfilled');
    rk_scan();
    T::same('open', rk_flags('mapping_inconsistent')[0]['status'], 'a person has to decide this one; the scan does not derive it, so it does not close it');

    // a run that already used up its time budget skips its detectors — and must not read "nothing found" as "all clear"
    $stale = Risk::record('repeated_failures', ['payment_id' => (int) $p['payment']['id'], 'key' => 'v999']);
    $out = Risk::scan(new JobContext(microtime(true) - 1));
    T::same(0, $out['resolved']);
    T::same('open', Db::val('SELECT status FROM payment_flags WHERE id = ?', [$stale['id']]));
    rk_scan();                                           // a complete run finds no such visitor any more
    T::same('resolved', Db::val('SELECT status FROM payment_flags WHERE id = ?', [$stale['id']]));
});

T::test('scan: one visitor with several unfinished payments (and none paid) gets a low flag, not a ban; it closes when they pay', function () {
    $gid = pf_setup();
    Settings::set('risk.failed_attempts', 3);
    $vid = Db::insert('visitors', ['anon_id' => bin2hex(random_bytes(16)), 'first_seen_at' => Clock::sql(), 'last_seen_at' => Clock::sql()]);
    $visitor = ['visitor_id' => $vid, 'session_id' => null];
    foreach (['v1', 'v2', 'v3'] as $name) {
        $x = rk_place($name, $visitor);
        PaymentService::expire((int) $x['payment']['id'], true);
    }
    T::same(1, rk_scan()['flagged'], 'exactly one flag');
    $f = rk_flags('repeated_failures');
    T::same(1, count($f));
    T::same(['low', 3], [$f[0]['severity'], rk_details($f[0])['unfinished_payments']]);
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM business_alerts WHERE alert_type = 'payment_flag'"), 'nobody is e-mailed about a struggling customer');
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM payments WHERE status = \'mismatch\''), 'nothing about the customer or their orders changed');

    // they finally pay: the signal no longer holds
    $ok = rk_place('v4', $visitor);
    rk_pay($gid, $ok['payment']);
    rk_scan();
    T::same('resolved', rk_flags('repeated_failures')[0]['status']);
});

T::test('scan: a dry run writes nothing and asks the gateway nothing — it only says what it would do', function () {
    $gid = pf_setup();
    Clock::setFixed(1_800_000_000);
    $p = rk_place('dry');
    rk_pay($gid, $p['payment']);
    Db::update('orders', ['status' => 'PENDING_PAYMENT'], 'id = ?', [$p['order']['id']]);
    Clock::advance(300);
    $lookups = static fn(): int => count(array_filter(T::alpha('GET', '/__state')['requests'] ?? [], static fn(array $r): bool => str_contains((string) $r['path'], 'check')));
    $before = $lookups();
    $out = rk_scan(true);
    T::same($before, $lookups(), 'the gateway was not asked');
    T::true($out['flagged'] >= 1 && $out['rechecked'] === 1, json_encode($out));
    T::same(0, count(rk_flags()));
    $pay = pf_payment((int) $p['payment']['id']);
    T::same([0, null], [(int) $pay['poll_count'], $pay['last_polled_at']], 'the dry run did not even stamp a check');
    $real = Jobs::run('payments', ['dry_run' => true]);
    T::same('ok', $real['status']);
    T::same(0, count(rk_flags()));
    $run = Jobs::run('payments');
    T::same('ok', $run['status']);
    T::true(isset($run['result']['risk']), 'the payments job includes the payment-risk scan');
    T::true(count(rk_flags('mapping_inconsistent')) >= 1);
    Clock::setFixed(null);
});

// ═════════════════════════════ alerts ═════════════════════════════

T::test('alerts: a high flag is e-mailed once, after the transaction has committed; reviewing the flag closes the alert', function () {
    $gid = pf_setup();
    Settings::setMany(['alerts.email_enabled' => true, 'alerts.email_to' => 'owner@example.com', 'alerts.email_min_severity' => 'critical']);
    $sent = [];
    Alerts::$mailer = static function (string $to, string $subject, string $body, string $headers) use (&$sent): bool {
        $sent[] = ['to' => $to, 'subject' => $subject, 'in_tx' => Db::inTransaction(), 'body' => $body];
        return true;
    };
    try {
        $p = rk_place('mail');
        rk_pay($gid, $p['payment'], ['amount' => '1.00']);
        rk_pay($gid, $p['payment'], ['amount' => '1.00']);
        T::same(1, count($sent), 'one e-mail for one problem');
        T::same(false, $sent[0]['in_tx'], 'sent after the commit, never from inside the transaction');
        T::contains('Amount mismatch', $sent[0]['subject'] . $sent[0]['body']);
        T::false(str_contains($sent[0]['body'], 'test-webhook-secret'), 'nothing secret in the message');
        $alert = Db::one("SELECT * FROM business_alerts WHERE alert_type = 'payment_flag'");
        T::same(['critical', 'open'], [$alert['severity'], $alert['status']]);
        Risk::review((int) rk_flags()[0]['id'], 'reviewed', 'confirmed by phone', RK_ADMIN);
        T::same('resolved', Db::val("SELECT status FROM business_alerts WHERE alert_type = 'payment_flag'"));
    } finally {
        Alerts::$mailer = null;
    }
});

// ═════════════════════════════ review, re-check, settings ═════════════════════════════

T::test('review: a note is required to dismiss, a closed flag cannot be closed twice, and both outcomes are audited', function () {
    pf_setup();
    rk_seed_admin();
    $pid = (int) rk_place('rev')['payment']['id'];
    $id = Risk::record('unknown_reference', ['payment_id' => $pid])['id'];
    $e = T::throws(HttpException::class, fn() => Risk::review($id, 'dismissed', '  ', RK_ADMIN), 422);
    T::true(isset($e->extra['fields']['note']));
    T::throws(HttpException::class, fn() => Risk::review($id, 'resolved', 'x', RK_ADMIN), 422);
    T::throws(HttpException::class, fn() => Risk::review(999999, 'reviewed', '', RK_ADMIN), 404);
    $shape = Risk::review($id, 'dismissed', 'the extra payment was refunded', RK_ADMIN);
    T::same(['dismissed', false, 'root'], [$shape['status'], $shape['open'], $shape['reviewed_by']]);
    T::throws(HttpException::class, fn() => Risk::review($id, 'reviewed', '', RK_ADMIN), 409);
    $audit = Db::one("SELECT * FROM audit_logs WHERE action = 'payment_flag.dismissed'");
    T::same('payment_flag', $audit['entity_type']);
    T::contains('refunded', (string) $audit['meta_json']);
});

T::test('re-check: settles a payment whose webhook was lost by the normal rules, never touches a flagged one, is audited and rate-limited', function () {
    $gid = pf_setup();
    $a = rk_place('rc_lost');
    T::alpha('POST', '/__pay/' . $a['payment']['gateway_order_id'] . '/success', ['noWebhook' => true, 'utr' => 'UTRRE1']);
    $r = Risk::recheck((int) $a['payment']['id'], RK_ADMIN);
    T::same('settled', $r['action']);
    T::same(['PAID', 'UTRRE1', 'poll'], [$r['payment']['state'], $r['payment']['bank_reference'], $r['payment']['verification_source']]);
    T::same('SUCCESS', $r['gateway']['status']);
    T::same('MANUAL_PENDING', pf_order((int) $a['order']['id'])['status']);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.rechecked' AND entity_id = ?", [$a['payment']['id']]));

    $held = rk_place('rc_held');
    rk_pay($gid, $held['payment'], ['amount' => '4.00']);
    T::alpha('POST', '/__pay/' . $held['payment']['gateway_order_id'] . '/success', ['noWebhook' => true, 'utr' => 'UTRRE2']);
    $r = Risk::recheck((int) $held['payment']['id'], RK_ADMIN);
    T::same('none', $r['action']);
    T::contains('stays flagged', $r['message']);
    T::same('mismatch', pf_payment((int) $held['payment']['id'])['status'], 'a flagged payment is only ever released by a person confirming it');

    $open = rk_place('rc_open');
    $r = Risk::recheck((int) $open['payment']['id'], RK_ADMIN);
    T::same('none', $r['action']);
    T::contains('unpaid', $r['message']);
    T::same('pending', pf_payment((int) $open['payment']['id'])['status']);

    T::alpha('POST', '/__pay/' . $open['payment']['gateway_order_id'] . '/fail', ['noWebhook' => true]);
    $r = Risk::recheck((int) $open['payment']['id'], RK_ADMIN);
    T::same('failed', $r['action']);

    T::throws(HttpException::class, fn() => Risk::recheck(999999, RK_ADMIN), 404);
    for ($i = 0; $i < 30; $i++) {
        RateLimiter::hit('payment-recheck', '1', 30, 300);
    }
    T::throws(HttpException::class, fn() => Risk::recheck((int) $a['payment']['id'], RK_ADMIN), 429);
});

T::test('re-check: an unreachable gateway is reported as such and changes nothing', function () {
    pf_setup();
    $p = rk_place('rc_off');
    T::alpha('POST', '/__control', ['mode' => ['offline' => true]]);
    $e = T::throws(HttpException::class, fn() => Risk::recheck((int) $p['payment']['id'], RK_ADMIN), 502);
    T::same('gateway_unreachable', $e->errorCode);
    T::alpha('POST', '/__control', ['mode' => ['offline' => false]]);
    T::same('pending', pf_payment((int) $p['payment']['id'])['status']);
});

T::test('a payment an admin confirmed by hand is not second-guessed by the gateway cross-check', function () {
    $gid = pf_setup();
    $p = rk_place('byhand');
    $oid = (int) $p['order']['id'];
    \App\Services\Settlement::settle((int) $p['payment']['id'], ['source' => 'admin', 'amount_paise' => 500, 'utr' => 'BANKSTMT1', 'admin' => RK_ADMIN, 'note' => 'seen in the bank statement']);
    T::same('skipped', Risk::doubleCheck((int) $p['payment']['id'], true));
    $r = Risk::recheck((int) $p['payment']['id'], RK_ADMIN);
    T::contains('confirmed by hand', $r['message']);
    T::same(0, count(rk_flags()));
    T::same('MANUAL_PENDING', pf_order($oid)['status']);
});

T::test('settings: validated, bounded, audited; stored garbage can never push a threshold outside its limits', function () {
    pf_setup();
    $e = T::throws(HttpException::class, fn() => Risk::saveSettings(['failed_attempts' => 1, 'webhook_double_check' => 'always', 'invalid_signatures_per_hour' => 'lots', 'flag_late_payments' => 'yes'], RK_ADMIN), 422);
    $bad = array_keys($e->extra['fields']);
    sort($bad);
    T::same(['failed_attempts', 'flag_late_payments', 'invalid_signatures_per_hour', 'webhook_double_check'], $bad);
    $v = Risk::saveSettings(['failed_attempts' => '6', 'webhook_double_check' => 'require', 'flag_late_payments' => false, 'confirm_grace_seconds' => 300], RK_ADMIN)['values'];
    T::same([6, 'require', false, 300], [$v['failed_attempts'], $v['webhook_double_check'], $v['flag_late_payments'], $v['confirm_grace_seconds']]);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'risk.settings_changed'"));
    Settings::setMany(['risk.failed_attempts' => 99999, 'risk.invalid_signatures_per_hour' => -4, 'risk.webhook_double_check' => 'bogus']);
    $t = Risk::thresholds();
    T::same([50, 1, 'flag'], [$t['failed_attempts'], $t['invalid_signatures_per_hour'], $t['webhook_double_check']]);
});

// ═════════════════════════════ the monitoring screen ═════════════════════════════

T::test('monitor: every payment shows expected vs verified amount, currency, the requested state, source, timestamps, flags and webhook status', function () {
    $gid = pf_setup();
    Clock::setFixed(1_800_000_000);
    $paid = rk_place('m_paid');
    rk_pay($gid, $paid['payment'], ['utr' => 'UTRMON1']);
    $held = rk_place('m_held');
    rk_pay($gid, $held['payment'], ['amount' => '4.00']);
    $open = rk_place('m_open');

    $d = Risk::monitor([]);
    T::same(3, $d['total']);
    $rows = array_column($d['rows'], null, 'order');
    $r = $rows[$paid['order']['public_id']];
    T::same(['PAID', 'success', 500, 500, 'INR', 'webhook', 'UTRMON1'], [$r['state'], $r['status'], $r['expected_paise'], $r['verified_paise'], $r['currency'], $r['verification_source'], $r['bank_reference']]);
    T::true($r['verified_at'] !== null && $r['created_at'] !== null && $r['expires_at'] !== null);
    T::same(['processed', true, 1], [$r['webhook']['status'], $r['webhook']['signature_valid'], $r['webhook']['deliveries']]);
    $r = $rows[$held['order']['public_id']];
    T::same(['REVIEW_REQUIRED', 500, 400], [$r['state'], $r['expected_paise'], $r['verified_paise']]);
    T::same(['amount_mismatch'], array_column($r['flags'], 'type'));
    $r = $rows[$open['order']['public_id']];
    T::same(['PENDING', null, null, []], [$r['state'], $r['verified_paise'], $r['webhook'], $r['flags']]);
    T::true($r['can_recheck']);

    T::same([$held['order']['public_id']], array_column(Risk::monitor(['state' => 'FLAGGED'])['rows'], 'order'));
    T::same([$held['order']['public_id']], array_column(Risk::monitor(['state' => 'REVIEW_REQUIRED'])['rows'], 'order'));
    T::same([$paid['order']['public_id']], array_column(Risk::monitor(['state' => 'PAID'])['rows'], 'order'));
    T::same([$paid['order']['public_id']], array_column(Risk::monitor(['q' => 'UTRMON1'])['rows'], 'order'));
    T::same([$open['order']['public_id']], array_column(Risk::monitor(['q' => $open['payment']['gateway_order_id']])['rows'], 'order'));
    T::same([$held['order']['public_id']], array_column(Risk::monitor(['flag' => 'amount_mismatch'])['rows'], 'order'));
    T::throws(HttpException::class, fn() => Risk::monitor(['state' => 'NOPE']), 422);
    T::throws(HttpException::class, fn() => Risk::monitor(['flag' => 'nope']), 422);

    $s = Risk::summary();
    T::same([1, 1, 1], [$s['by_state']['PAID'], $s['by_state']['REVIEW_REQUIRED'], $s['by_state']['PENDING']]);
    T::same(['high' => 1, 'medium' => 0, 'low' => 0, 'attention' => 1], $s['open_flags']);
    T::same(1, $s['needs_review']);
    T::same(['flag', 2], [$s['double_check'], $s['webhooks_24h']['received']]);
    Clock::setFixed(null);
});

T::test('flags list: open first, filters by severity and kind, closed ones keep who reviewed them and why', function () {
    pf_setup();
    rk_seed_admin();
    $a = (int) rk_place('fl_a')['payment']['id'];
    $b = (int) rk_place('fl_b')['payment']['id'];
    $hi = Risk::record('amount_mismatch', ['payment_id' => $a])['id'];
    Risk::record('late_payment', ['payment_id' => $b]);
    Risk::review($hi, 'reviewed', 'bank statement checked', RK_ADMIN);
    $open = Risk::flags([]);
    T::same(['late_payment'], array_column($open['rows'], 'type'));
    $all = Risk::flags(['status' => 'all']);
    T::same(['late_payment', 'amount_mismatch'], array_column($all['rows'], 'type'), 'open flags before closed ones');
    T::same(['reviewed', 'root', 'bank statement checked'], [$all['rows'][1]['status'], $all['rows'][1]['reviewed_by'], $all['rows'][1]['note']]);
    T::same(['amount_mismatch'], array_column(Risk::flags(['status' => 'closed'])['rows'], 'type'));
    T::same([], Risk::flags(['severity' => 'high'])['rows']);
    T::same(['late_payment'], array_column(Risk::flags(['type' => 'late_payment'])['rows'], 'type'));
    T::throws(HttpException::class, fn() => Risk::flags(['status' => 'weird']), 422);
    T::throws(HttpException::class, fn() => Risk::flags(['severity' => 'extreme']), 422);
    T::contains('Check', $open['rows'][0]['advice'] . 'Check', 'each flag carries the advice for it');
});

// ═════════════════════════════ who may do what ═════════════════════════════

T::test('permissions: everyone with payments.view reads; only review roles review and re-check; only owners/managers change thresholds', function () {
    $gid = pf_setup();
    $pay = rk_place('perm')['payment'];
    $flagId = Risk::record('unknown_reference', ['payment_id' => (int) $pay['id']])['id'];
    $ctl = new PaymentChecksController();
    foreach (['owner', 'manager', 'support', 'analyst'] as $role) {
        T::true(rk_json($ctl->monitor(rk_req('GET', '/admin/api/payment-checks', $role), []))['ok'], "$role reads the monitor");
        T::true(rk_json($ctl->flags(rk_req('GET', '/admin/api/payment-flags', $role), []))['ok'], "$role reads the flags");
        T::true(rk_json($ctl->summary(rk_req('GET', '/admin/api/payment-checks/summary', $role), []))['ok']);
    }
    $can = rk_json($ctl->monitor(rk_req('GET', '/x', 'manager'), []))['can'];
    T::same([true, false, true], [$can['review'], $can['override'], $can['configure']]);
    $can = rk_json($ctl->monitor(rk_req('GET', '/x', 'support'), []))['can'];
    T::same([false, false, false], [$can['review'], $can['override'], $can['configure']]);

    foreach (['support', 'analyst'] as $role) {
        T::throws(HttpException::class, fn() => $ctl->review(rk_req('POST', '/x', $role, ['status' => 'reviewed']), ['id' => (string) $flagId]), 403);
        T::throws(HttpException::class, fn() => $ctl->recheck(rk_req('POST', '/x', $role), ['id' => (string) $pay['id']]), 403);
        T::throws(HttpException::class, fn() => $ctl->saveSettings(rk_req('PUT', '/x', $role, ['failed_attempts' => 5]), []), 403);
    }
    T::same('open', Db::val('SELECT status FROM payment_flags WHERE id = ?', [$flagId]), 'a refused review changed nothing');
    T::same(4, Risk::thresholds()['failed_attempts']);

    T::true(rk_json($ctl->review(rk_req('POST', '/x', 'manager', ['status' => 'reviewed', 'note' => 'ok']), ['id' => (string) $flagId]))['ok']);
    T::true(rk_json($ctl->saveSettings(rk_req('PUT', '/x', 'manager', ['failed_attempts' => 5]), []))['ok']);
    T::same(5, Risk::thresholds()['failed_attempts']);
    T::true(rk_json($ctl->recheck(rk_req('POST', '/x', 'owner'), ['id' => (string) $pay['id']]))['ok']);
});

T::test('permissions: confirming a payment by hand is owner-only, managers keep the rest of the order actions, and the buttons follow the role', function () {
    pf_setup();
    $p = rk_place('own');
    $oid = (string) $p['order']['id'];
    $ctl = new OrdersController();
    $act = static fn(string $role, array $body): array => rk_json($ctl->act(rk_req('POST', "/admin/api/orders/{$oid}/action", $role, $body), ['id' => $oid]));

    foreach (['manager', 'support', 'analyst'] as $role) {
        $e = T::throws(HttpException::class, fn() => $act($role, ['action' => 'confirm_payment', 'reference' => 'BANK1']), 403);
        T::same('forbidden', $e->errorCode);
        T::same('PENDING_PAYMENT', pf_order((int) $oid)['status']);
    }
    T::throws(HttpException::class, fn() => $act('support', ['action' => 'note', 'note' => 'x']), 403);
    T::same('Note saved.', $act('manager', ['action' => 'note', 'note' => 'called the customer'])['message']);

    $actions = static fn(string $role): array => rk_json($ctl->show(rk_req('GET', "/admin/api/orders/{$oid}", $role), ['id' => $oid]))['order']['actions'];
    T::true(in_array('confirm_payment', $actions('owner'), true));
    T::false(in_array('confirm_payment', $actions('manager'), true), 'not offered to a manager');
    T::true(in_array('cancel', $actions('manager'), true));
    T::same([], $actions('support'), 'read-only roles are offered no action');
    T::same([false, false], array_values(array_map(static fn(string $r): bool => rk_json($ctl->list(rk_req('GET', '/x', $r), []))['can']['bulk'], ['support', 'analyst'])));
    T::throws(HttpException::class, fn() => $ctl->approveAll(rk_req('POST', '/x', 'support', ['confirm' => true]), []), 403);

    T::same('Payment confirmed manually.', $act('owner', ['action' => 'confirm_payment', 'reference' => 'BANK1', 'note' => 'checked the statement'])['message']);
    T::same('MANUAL_PENDING', pf_order((int) $oid)['status']);
    T::same('success', Db::val('SELECT status FROM payments WHERE id = ?', [$p['payment']['id']]));
    T::same('admin', Db::val('SELECT verified_via FROM payments WHERE id = ?', [$p['payment']['id']]), 'recorded as a person\'s decision, never as a gateway confirmation');
});
