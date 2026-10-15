<?php
declare(strict_types=1);

use App\Controllers\Admin\JobsController;
use App\Controllers\Admin\OrdersController;
use App\Controllers\Admin\RecoveryController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\Fulfilment;
use App\Services\Jobs;
use App\Services\Orders;
use App\Services\Recovery;
use App\Services\Risk;
use App\Services\Settings;
use App\Services\Settlement;
use App\Support\Clock;
use App\Support\Status;

/**
 * Order recovery: the marks Fulfilment leaves, the queue and its categories, the safe retry rules (an unknown outcome is never resent by
 * itself and needs a person's verification), linking a provider order, review / escalate / resolve, the recovery job and who may do what.
 * Uses the real checkout / settlement / fulfilment code against the mock gateway and the mock SMM panel (helpers from PaymentFlowTest and
 * FulfilmentTest).
 */

const RC_ADMIN = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];

/** @return array<string,mixed> the order row */
function rc_order(int $id): array { return Orders::byId($id); }

/** Paid and left PAID: settled without the post-response submission (the request that settled it "died"). @return array order row */
function rc_waiting(string $name): array
{
    static $n = 0;
    $p = pf_place('ig-followers-50', 'https://www.instagram.com/' . $name . '/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    Settlement::settle((int) $p['payment']['id'], ['source' => 'webhook', 'amount_paise' => 500, 'utr' => 'RCUTR' . (++$n) . bin2hex(random_bytes(3))]);
    return rc_order((int) $p['order']['id']);
}

/** An order that really exists at the (mock) provider, made behind the shop's back — what a person finds in the provider panel. @return string its id */
function rc_mock_add(string $link, int $qty = 50, string $service = '1001'): string
{
    $ch = curl_init('http://127.0.0.1:4020/api/v2');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['key' => 'smm-key-ok', 'action' => 'add', 'service' => $service, 'link' => $link, 'quantity' => $qty]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $out = json_decode((string) curl_exec($ch), true);
    unset($ch);
    return (string) ($out['order'] ?? '');
}

/** A paid AUTO order whose outcome is unknown: the provider's answer was garbled. */
function rc_unknown(int $gid, string $name): array
{
    T::smm('POST', '/__control', ['garbled' => true]);
    $o = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/' . $name . '/');
    T::smm('POST', '/__control', ['garbled' => false]);
    return $o;
}

function rc_cats(int $id): array { return Recovery::one($id)['categories']; }

// ═════════════════════════════ the marks Fulfilment leaves ═════════════════════════════

T::test('recovery marks: every attempt is stamped; a scheduled retry is auto_retry, a later success is resolved', function () {
    ['gid' => $gid] = ff_setup();
    Clock::setFixed(1_800_000_000);
    ff_provider_down();
    $o = ff_paid($gid);
    T::same(['PAID', 'auto_retry', Clock::sql(1_800_000_000), 1], [$o['status'], $o['recovery_status'], $o['last_attempt_at'], (int) $o['provider_attempts']]);
    T::contains('Attempt 1 of 5', (string) Db::val("SELECT note FROM order_status_history WHERE order_id = ? AND note LIKE 'Provider unreachable%'", [$o['id']]));
    T::same(['retry'], rc_cats((int) $o['id']));
    T::same('QUEUED', Recovery::one((int) $o['id'])['state'], 'a paid order waiting for a retry is QUEUED in the standard vocabulary');

    ff_provider_up();
    Clock::setFixed(1_800_000_100);
    Fulfilment::submitDue();
    $o = rc_order((int) $o['id']);
    T::same(['SUBMITTED', 'resolved', Clock::sql(1_800_000_100), 2], [$o['status'], $o['recovery_status'], $o['last_attempt_at'], (int) $o['provider_attempts']]);
    T::same([], rc_cats((int) $o['id']), 'recovered: out of the queue');
    Clock::setFixed(null);
});

T::test('recovery marks: an outcome nobody can know and a setup problem need review; a plain refusal is a known failure', function () {
    ['gid' => $gid] = ff_setup();
    $amb = rc_unknown($gid, 'amb_one');
    T::same(['FAILED', 'ambiguous', 'needs_review', 'NEEDS_REVIEW'], [$amb['status'], $amb['failure_kind'], $amb['recovery_status'], Status::specOrder($amb)]);

    T::configureSmm('smm-key-poor');                                       // the provider refuses: not enough balance
    $rej = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/rej_one/');
    T::same(['FAILED', 'rejected', null, 'FAILED'], [$rej['status'], $rej['failure_kind'], $rej['recovery_status'], Status::specOrder($rej)]);

    T::configureSmm();
    $p = pf_place('ig-followers-50', 'https://www.instagram.com/mis_one/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    Db::exec("UPDATE orders SET smm_service_id = '' WHERE id = ?", [$p['order']['id']]);   // the SMM id disappears AFTER the customer paid (the order carries its own copy)
    pf_webhook($gid, pf_evt($p['payment']));
    $mis = rc_order((int) $p['order']['id']);
    T::same(['FAILED', 'misconfigured', 'needs_review'], [$mis['status'], $mis['failure_kind'], $mis['recovery_status']]);
});

T::test('retry policy: the maximum number of attempts is a setting (1–10), shown in the note, and the schedule grows then stays at an hour', function () {
    ['gid' => $gid] = ff_setup();
    T::same(5, Fulfilment::maxAttempts());
    T::same([60, 300, 1200, 3600], Fulfilment::BACKOFF);
    Settings::set('recovery.max_attempts', 2);
    Clock::setFixed(1_800_000_000);
    ff_provider_down();
    $o = ff_paid($gid);
    T::same('PAID', $o['status']);
    T::contains('Attempt 1 of 2', (string) Db::val("SELECT note FROM order_status_history WHERE order_id = ? AND note LIKE 'Provider unreachable%'", [$o['id']]));
    Clock::setFixed(1_800_000_100);
    Fulfilment::submitDue();
    $o = rc_order((int) $o['id']);
    T::same(['FAILED', 'unreachable', 2, null], [$o['status'], $o['failure_kind'], (int) $o['provider_attempts'], $o['recovery_status']], 'the second attempt was the last: a person is asked, and it is a known failure');
    Settings::setMany(['recovery.max_attempts' => 0]);
    T::same(1, Fulfilment::maxAttempts(), 'garbage in the database is clamped');
    Settings::setMany(['recovery.max_attempts' => 99]);
    T::same(10, Fulfilment::maxAttempts());
    Clock::setFixed(null);
});

// ═════════════════════════════ the queue ═════════════════════════════

T::test('queue: every category is derived from the orders, an order can be in two, and unpaid / manual / delivered orders never appear', function () {
    ['gid' => $gid] = ff_setup();
    Clock::setFixed(1_800_000_000);
    $now = Clock::now();

    $waiting = rc_waiting('q_waiting');
    ff_provider_down();
    $retry = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/q_retry/');
    ff_provider_up();
    $unknown = rc_unknown($gid, 'q_unknown');
    T::configureSmm('smm-key-poor');
    $failed = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/q_failed/');
    T::configureSmm();
    $sending = rc_waiting('q_sending');
    Orders::transition((int) $sending['id'], Status::PROCESSING, ['actor' => 'system', 'note' => 'claimed']);
    Db::update('orders', ['updated_at' => Clock::sql($now - 400)], 'id = ?', [$sending['id']]);
    $stale = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/q_stale/');
    Db::update('orders', ['submitted_at' => Clock::sql($now - 3000), 'last_sync_at' => Clock::sql($now - 2500)], 'id = ?', [$stale['id']]);
    $errors = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/q_errors/');
    Db::update('orders', ['last_sync_at' => Clock::sql($now - 60)], 'id = ?', [$errors['id']]);
    for ($i = 0; $i < 3; $i++) {
        Db::insert('smm_transactions', ['order_id' => $errors['id'], 'provider_id' => $errors['smm_provider_id'], 'action' => 'status', 'ok' => 0, 'error' => 'timeout', 'provider_order_id' => $errors['provider_order_id'], 'created_at' => Clock::sql($now - 600)]);
    }
    $review = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/q_review/');
    Db::update('orders', ['last_sync_at' => Clock::sql($now - 60)], 'id = ?', [$review['id']]);
    Recovery::act((int) $review['id'], ['action' => 'mark_review', 'note' => 'looks odd'], null, RC_ADMIN);

    // none of these belong in the queue
    $manual = pf_place('ig-views-1k', 'https://www.instagram.com/p/NOTQUEUED1/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    pf_webhook($gid, pf_evt($manual['payment']));
    $unpaid = pf_place('ig-followers-50', 'https://www.instagram.com/q_unpaid/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    App\Services\PaymentService::fail((int) $unpaid['payment']['id'], 'declined');           // a FAILED order whose PAYMENT failed is not a delivery problem
    $done = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/q_done/');
    Db::update('orders', ['last_sync_at' => Clock::sql($now - 60)], 'id = ?', [$done['id']]);
    Orders::transition((int) $done['id'], Status::COMPLETED, ['actor' => 'provider', 'note' => 'done']);

    T::same(['waiting'], rc_cats((int) $waiting['id']));
    T::same(['retry'], rc_cats((int) $retry['id']));
    T::same(['unknown', 'review'], rc_cats((int) $unknown['id']), 'unknown outcome carries the review mark too');
    T::same(['failed'], rc_cats((int) $failed['id']));
    T::same(['sending'], rc_cats((int) $sending['id']));
    T::same(['stale'], rc_cats((int) $stale['id']));
    T::same(['errors'], rc_cats((int) $errors['id']));
    T::same(['review'], rc_cats((int) $review['id']));
    T::same([], rc_cats((int) $done['id']));

    $q = Recovery::queue();
    T::same(8, $q['total']);
    T::same(['unknown', 'review', 'failed', 'sending', 'errors', 'stale', 'waiting', 'retry'], array_column($q['rows'], 'primary'), 'a person is needed → stuck → waiting');
    $ids = array_column($q['rows'], 'id');
    foreach ([$manual['order']['id'], $unpaid['order']['id'], $done['id']] as $never) {
        T::false(in_array((int) $never, $ids, true), 'order ' . $never . ' is not a recovery matter');
    }
    $s = Recovery::summary();
    T::same(['unknown' => 1, 'review' => 2, 'failed' => 1, 'sending' => 1, 'errors' => 1, 'stale' => 1, 'waiting' => 1, 'retry' => 1], $s['counts']);
    T::same([3, 8], [$s['needs_person'], $s['in_queue']], 'unknown + review + failed, counting an order once');
    T::same(['unknown'], array_column(Recovery::queue(['category' => 'unknown'])['rows'], 'primary'));
    T::same([(int) $waiting['id']], array_column(Recovery::queue(['q' => $waiting['public_id']])['rows'], 'id'));
    T::same(1, count(Recovery::queue(['q' => 'q_retry'])['rows']), 'search finds the account link');
    T::throws(HttpException::class, fn() => Recovery::queue(['category' => 'nope']), 422);
    $row = $q['rows'][0];
    T::same([$unknown['public_id'], 'NEEDS_REVIEW', 'ambiguous', 1, 5], [$row['public_id'], $row['state'], $row['failure_kind'], $row['attempts'], $row['max_attempts']]);
    T::true($row['error'] !== null && !str_contains((string) $row['error'], SMB_ROOT), 'a safe, short error message');
    Clock::setFixed(null);
});

// ═════════════════════════════ retrying safely ═════════════════════════════

T::test('retry: a known failure can be resent after the cause is fixed — once, audited, in the order\'s timeline', function () {
    ['gid' => $gid] = ff_setup();
    T::configureSmm('smm-key-poor');
    $o = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/fix_me/');
    T::same('FAILED', $o['status']);
    $offered = Recovery::actionsFor($o);
    foreach (['retry_submit', 'link_provider_order', 'move_to_manual', 'mark_review', 'escalate', 'resolve_review'] as $a) {
        T::true(in_array($a, $offered, true), "$a is offered for a failed AUTO order: " . implode(',', $offered));
    }
    T::configureSmm();                                                   // fixed: the balance is topped up
    $r = Recovery::act((int) $o['id'], ['action' => 'retry_submit'], null, RC_ADMIN);
    T::same(['Submitted to the provider.', 'submitted'], [$r['message'], $r['result']]);
    $o = rc_order((int) $o['id']);
    T::same(['SUBMITTED', 2], [$o['status'], (int) $o['provider_attempts']]);
    T::same(1, ff_smm_orders());
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.recovery.retry' AND entity_id = ?", [$o['id']]));
    T::same('admin', Db::val("SELECT actor_type FROM order_status_history WHERE order_id = ? AND to_status = 'PROCESSING' ORDER BY id DESC LIMIT 1", [$o['id']]));
    T::throws(HttpException::class, fn() => Recovery::act((int) $o['id'], ['action' => 'retry_submit'], null, RC_ADMIN), 409);      // no longer failed: nothing to resend
});

T::test('retry: an order whose outcome is UNKNOWN is resent only after the admin states — with a note — that the provider panel has no order for it', function () {
    ['gid' => $gid] = ff_setup();
    $o = rc_unknown($gid, 'unknown_one');
    $id = (int) $o['id'];
    $e = T::throws(HttpException::class, fn() => Recovery::act($id, ['action' => 'retry_submit'], null, RC_ADMIN), 422);
    T::same('verification_required', $e->errorCode);
    T::same(['verified_absent', 'note'], array_keys($e->extra['fields']));
    T::throws(HttpException::class, fn() => Recovery::act($id, ['action' => 'retry_submit', 'verified_absent' => true, 'note' => 'ok'], null, RC_ADMIN), 422);    // a note of substance
    T::throws(HttpException::class, fn() => Recovery::act($id, ['action' => 'retry_submit', 'verified_absent' => 'yes', 'note' => 'looked in the panel'], null, RC_ADMIN), 422);   // exactly true
    T::same(0, ff_smm_orders(), 'nothing was sent by any of the refused attempts');
    T::same(1, (int) rc_order($id)['provider_attempts']);

    $r = Recovery::act($id, ['action' => 'retry_submit', 'verified_absent' => true, 'note' => 'Searched the panel for this link: no order'], null, RC_ADMIN);
    T::same('submitted', $r['result']);
    $o = rc_order($id);
    T::same(['SUBMITTED', 'resolved', 1], [$o['status'], $o['recovery_status'], ff_smm_orders()]);
    T::contains('no order exists for this link', (string) Db::val("SELECT note FROM order_status_history WHERE order_id = ? AND note LIKE 'Checked at the provider panel%'", [$id]));
    T::same(true, json_decode((string) Db::val("SELECT meta_json FROM audit_logs WHERE action = 'order.recovery.retry' AND entity_id = ?", [$id]), true)['outcome_was_unknown']);
});

T::test('retry: the cron never resends an unknown outcome, and a person who was asked to look pauses the automatic sending of a waiting order', function () {
    ['gid' => $gid] = ff_setup();
    Clock::setFixed(1_800_000_000);
    $unknown = rc_unknown($gid, 'cron_unknown');
    $waiting = rc_waiting('cron_waiting');
    Recovery::act((int) $waiting['id'], ['action' => 'mark_review', 'note' => 'check the link first'], null, RC_ADMIN);
    Clock::setFixed(1_800_000_000 + 7200);
    T::same(0, Fulfilment::submitDue()['attempted'], 'neither is sent: one is unknown, one is waiting for a person');
    T::same(0, ff_smm_orders());
    Recovery::act((int) $waiting['id'], ['action' => 'resolve_review', 'note' => 'link is fine'], null, RC_ADMIN);
    T::same(1, Fulfilment::submitDue()['attempted'], 'resolved: the automatic sending carries on');
    T::same('SUBMITTED', rc_order((int) $waiting['id'])['status']);
    T::same('FAILED', rc_order((int) $unknown['id'])['status'], 'the unknown outcome is still waiting for a person');
    T::same(1, ff_smm_orders());
    Clock::setFixed(null);
});

T::test('retry: two admins resending the same failed order in the same instant create ONE provider order', function () {
    ['gid' => $gid] = ff_setup();
    T::configureSmm('smm-key-poor');
    $o = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/double_click/');
    T::configureSmm();
    $go = sys_get_temp_dir() . '/smb-go3-' . getmypid();
    @unlink($go);
    $procs = [];
    $pipes = [];
    for ($i = 0; $i < 4; $i++) {
        $procs[] = proc_open(['php', SMB_ROOT . '/tests/fixtures/recovery_worker.php', (string) $o['id'], $go, 'retry_submit'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    usleep(800_000);
    touch($go);
    $out = [];
    foreach ($procs as $i => $proc) {
        $out[] = trim((string) stream_get_contents($pipes[$i][1]));
        proc_close($proc);
    }
    @unlink($go);
    $c = array_count_values($out);
    T::same(1, $c['submitted'] ?? 0, json_encode($out));
    T::same(1, ff_smm_orders(), 'the provider has exactly one order for it');
    T::same('SUBMITTED', rc_order((int) $o['id'])['status']);
});

// ═════════════════════════════ linking a provider order ═════════════════════════════

T::test('link: the provider order a person found in the panel is verified with the provider and adopted — no second order is created', function () {
    ['gid' => $gid] = ff_setup();
    $o = rc_unknown($gid, 'link_me');
    $id = (int) $o['id'];
    $found = rc_mock_add('https://www.instagram.com/link_me/', 50);          // the provider did get it after all
    T::same('70001', $found);
    $adds = count(ff_smm_adds());

    $e = T::throws(HttpException::class, fn() => Recovery::act($id, ['action' => 'link_provider_order', 'provider_order_id' => 'no spaces allowed!'], null, RC_ADMIN), 422);
    T::true(isset($e->extra['fields']['provider_order_id']));
    $e = T::throws(HttpException::class, fn() => Recovery::act($id, ['action' => 'link_provider_order', 'provider_order_id' => '99999'], null, RC_ADMIN), 409);
    T::same('not_confirmed', $e->errorCode);
    T::same(['FAILED', null], [rc_order($id)['status'], rc_order($id)['provider_order_id']], 'an id the provider does not know changes nothing');

    $r = Recovery::act($id, ['action' => 'link_provider_order', 'provider_order_id' => $found, 'note' => 'seen in the panel'], null, RC_ADMIN);
    T::same('linked', $r['result']);
    $o = rc_order($id);
    T::same(['SUBMITTED', '70001', 'resolved', null, 'Pending'], [$o['status'], $o['provider_order_id'], $o['recovery_status'], $o['failure_kind'], $o['provider_status']]);
    T::same($adds, count(ff_smm_adds()), 'linking never creates a provider order');
    T::same(1, ff_smm_orders());
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.recovery.linked' AND entity_id = ?", [$id]));
    T::true((int) Db::val("SELECT COUNT(*) FROM smm_transactions WHERE order_id = ? AND action = 'status' AND request_json LIKE '%link%'", [$id]) >= 1, 'the provider\'s confirmation is in the call log');
    T::same(['PROCESSING', 'SUBMITTED'], array_slice(Db::col('SELECT to_status FROM order_status_history WHERE order_id = ? ORDER BY id', [$id]), -2), 'a valid timeline');
    T::same([], rc_cats($id), 'out of the queue; the regular sync follows it from now on');
    T::same(false, in_array('link_provider_order', Recovery::actionsFor($o), true), 'an order that has a provider order cannot be linked again');
});

T::test('link: a provider order already linked to another order is refused, even when two admins try at the same instant', function () {
    ['gid' => $gid] = ff_setup();
    $a = rc_unknown($gid, 'link_a');
    $b = rc_unknown($gid, 'link_b');
    $found = rc_mock_add('https://www.instagram.com/link_a/', 50);
    $go = sys_get_temp_dir() . '/smb-go4-' . getmypid();
    @unlink($go);
    $procs = [];
    $pipes = [];
    foreach ([$a, $b, $a, $b] as $i => $o) {
        $procs[] = proc_open(['php', SMB_ROOT . '/tests/fixtures/recovery_worker.php', (string) $o['id'], $go, 'link_provider_order', $found], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    usleep(800_000);
    touch($go);
    $out = [];
    foreach ($procs as $i => $proc) {
        $out[] = trim((string) stream_get_contents($pipes[$i][1]));
        proc_close($proc);
    }
    @unlink($go);
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM orders WHERE provider_order_id = ?', [$found]), 'one provider order, one of our orders — ' . json_encode($out));
    T::same(1, count(array_filter($out, static fn(string $x): bool => $x === 'linked')), json_encode($out));
});

// ═════════════════════════════ people taking over ═════════════════════════════

T::test('review / escalate / resolve: who and when are kept, a note is required where it matters, and the timeline and audit log say so', function () {
    ['gid' => $gid] = ff_setup();
    Db::insert('admins', ['username' => 'root', 'email' => 'r@x.test', 'password_hash' => 'x', 'role' => 'owner', 'is_active' => 1, 'must_change_password' => 0, 'password_changed_at' => Clock::sql(), 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()]);
    $o = rc_unknown($gid, 'review_flow');
    $id = (int) $o['id'];
    T::same(false, in_array('mark_review', Recovery::actionsFor($o), true), 'already marked for review by the failure itself');
    $e = T::throws(HttpException::class, fn() => Recovery::act($id, ['action' => 'escalate', 'note' => ''], null, RC_ADMIN), 422);
    T::true(isset($e->extra['fields']['note']));
    Recovery::act($id, ['action' => 'escalate', 'note' => 'customer is chasing'], null, RC_ADMIN);
    $o = rc_order($id);
    T::same(['escalated', 1], [$o['recovery_status'], (int) $o['escalated_by']]);
    T::true($o['escalated_at'] !== null);
    $row = Recovery::one($id);
    T::same(['root', 'escalated'], [$row['escalated_by'], $row['recovery_status']]);
    T::same(false, in_array('escalate', $row['actions'], true), 'cannot escalate twice');
    T::contains('Escalated: customer is chasing', (string) Db::val('SELECT note FROM order_status_history WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$id]));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.recovery.escalated' AND entity_id = ?", [$id]));

    Recovery::act($id, ['action' => 'resolve_review', 'note' => 'refunded by hand and told the customer'], null, RC_ADMIN);
    T::same('resolved', rc_order($id)['recovery_status']);
    T::same([], rc_cats($id), 'resolved: the unknown outcome leaves the queue (the order itself stays FAILED until it is refunded or moved)');
    T::same('FAILED', rc_order($id)['status']);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.recovery.resolved' AND entity_id = ?", [$id]));
    // a NEW failure of the same order is not hidden by the old "resolved" mark
    Db::update('orders', ['status' => 'PAID', 'failure_kind' => null], 'id = ?', [$id]);
    T::smm('POST', '/__control', ['garbled' => true]);
    Fulfilment::submit($id);
    T::smm('POST', '/__control', ['garbled' => false]);
    T::same(['FAILED', 'needs_review'], [rc_order($id)['status'], rc_order($id)['recovery_status']]);
    T::same(['unknown', 'review'], rc_cats($id));
});

T::test('move to manual: a failed AUTO order goes to the manual queue, its recovery mark closes, and a live provider order is never abandoned', function () {
    ['gid' => $gid] = ff_setup();
    T::configureSmm('smm-key-poor');
    $o = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/to_manual/');
    T::configureSmm();
    $r = Recovery::act((int) $o['id'], ['action' => 'move_to_manual'], null, RC_ADMIN);
    T::same('Moved to the manual queue.', $r['message']);
    T::same(['MANUAL_PENDING', 'MANUAL', 'QUEUED'], [rc_order((int) $o['id'])['status'], rc_order((int) $o['id'])['mode'], Status::specOrder(rc_order((int) $o['id']))]);
    T::same('pending', Db::val('SELECT state FROM manual_orders WHERE order_id = ?', [$o['id']]));

    $live = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/live_one/');
    T::same(false, in_array('move_to_manual', Recovery::actionsFor($live), true), 'an order that is live at the provider cannot be moved');
    T::throws(HttpException::class, fn() => Recovery::act((int) $live['id'], ['action' => 'move_to_manual'], null, RC_ADMIN), 409);
});

T::test('sync: asks the provider for the order\'s status; an unreachable provider is reported and changes nothing', function () {
    ['gid' => $gid] = ff_setup();
    $o = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/sync_me/');
    T::contains('Provider status: Pending', Recovery::act((int) $o['id'], ['action' => 'sync'], null, RC_ADMIN)['message']);
    T::smm('POST', '/__control', ['offline' => true]);
    $e = T::throws(HttpException::class, fn() => Recovery::act((int) $o['id'], ['action' => 'sync'], null, RC_ADMIN), 502);
    T::same('provider_error', $e->errorCode);
    T::smm('POST', '/__control', ['offline' => false]);
    T::same('SUBMITTED', rc_order((int) $o['id'])['status']);
});

// ═════════════════════════════ the recovery job ═════════════════════════════

T::test('recovery job: a dry run says what it would do and changes nothing; a real run sends what is due, tidies the marks and records the queue', function () {
    ['gid' => $gid] = ff_setup();
    Clock::setFixed(1_800_000_000);
    $waiting = rc_waiting('job_waiting');
    $old = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/job_old/');
    Db::update('orders', ['status' => 'FAILED', 'failure_kind' => 'ambiguous', 'recovery_status' => null, 'provider_order_id' => null, 'provider_status' => null], 'id = ?', [$old['id']]);   // a failure from before this release: no mark yet
    Clock::advance(60);
    $adds = count(ff_smm_adds());
    $dry = Jobs::run('recovery', ['dry_run' => true]);
    T::same('ok', $dry['status']);
    T::same(1, $dry['result']['would_do']['to_submit']);
    T::same(1, $dry['result']['recovery']['marked_for_review']);
    T::same($adds, count(ff_smm_adds()), 'a dry run sends nothing');
    T::same(['PAID', null], [rc_order((int) $waiting['id'])['status'], rc_order((int) $old['id'])['recovery_status']], 'and changes nothing');

    $run = Jobs::run('recovery');
    T::same('ok', $run['status']);
    T::same(1, $run['result']['submit']['attempted']);
    T::same('SUBMITTED', rc_order((int) $waiting['id'])['status']);
    T::same('needs_review', rc_order((int) $old['id'])['recovery_status'], 'the unknown outcome now carries its review mark');
    T::same(1, $run['result']['recovery']['needs_person'], 'the queue size is part of the run\'s record');
    $row = Db::one("SELECT status, trigger_source, dry_run FROM job_runs WHERE job = 'recovery' ORDER BY id DESC LIMIT 1");
    T::same(['ok', 'cron', 0], [$row['status'], $row['trigger_source'], (int) $row['dry_run']]);
    Clock::setFixed(null);
});

// ═════════════════════════════ bulk approval ═════════════════════════════

T::test('Approve All leaves out a paid order whose payment has an open high-risk flag — and counts it — until a person has looked', function () {
    $gid = pf_setup();
    $ok = pf_place('ig-views-1k', 'https://www.instagram.com/p/BULKOK00001/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    $held = pf_place('ig-views-1k', 'https://www.instagram.com/p/BULKHELD01/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    pf_webhook($gid, pf_evt($ok['payment']));
    pf_webhook($gid, pf_evt($held['payment']));
    T::same([2, 0], [Fulfilment::approvableCount(), Fulfilment::heldBackCount()]);
    $flag = Risk::record('conflicting_status', ['payment_id' => (int) $held['payment']['id'], 'key' => 'p' . $held['payment']['id'] . ':webhook_not_confirmed', 'details' => ['kind' => 'webhook_not_confirmed']]);
    T::same('high', Db::val('SELECT severity FROM payment_flags WHERE id = ?', [$flag['id']]));
    T::same([1, 1], [Fulfilment::approvableCount(), Fulfilment::heldBackCount()]);
    $r = Fulfilment::approveAll(RC_ADMIN, Request::fake('POST', '/x'));
    T::same(1, $r['approved']);
    T::same(['MANUAL_APPROVED', 'MANUAL_PENDING'], [pf_order((int) $ok['order']['id'])['status'], pf_order((int) $held['order']['id'])['status']]);
    // it can still be approved on its own, with the flag in front of the admin …
    Fulfilment::approve((int) $held['order']['id'], RC_ADMIN, Request::fake('POST', '/x'));
    T::same('MANUAL_APPROVED', pf_order((int) $held['order']['id'])['status']);
});

T::test('Approve All: a flag that has been reviewed no longer holds an order back, and a low or medium flag never does', function () {
    $gid = pf_setup();
    $a = pf_place('ig-views-1k', 'https://www.instagram.com/p/BULKFLAG01/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    $b = pf_place('ig-views-1k', 'https://www.instagram.com/p/BULKFLAG02/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    pf_webhook($gid, pf_evt($a['payment']));
    pf_webhook($gid, pf_evt($b['payment']));
    $high = Risk::record('conflicting_status', ['payment_id' => (int) $a['payment']['id'], 'key' => 'x1', 'details' => ['kind' => 'webhook_not_confirmed']]);
    Risk::record('late_payment', ['payment_id' => (int) $b['payment']['id']]);                      // low
    T::same([1, 1], [Fulfilment::approvableCount(), Fulfilment::heldBackCount()]);
    Risk::review($high['id'], 'reviewed', 'checked the bank statement', RC_ADMIN);
    T::same([2, 0], [Fulfilment::approvableCount(), Fulfilment::heldBackCount()]);
});

// ═════════════════════════════ who may do what ═════════════════════════════

T::test('permissions: everyone with orders.view sees the queue, only roles with orders.recover are offered or may use an action', function () {
    ['gid' => $gid] = ff_setup();
    $o = rc_unknown($gid, 'perm_one');
    $ctl = new RecoveryController();
    foreach (['owner', 'manager', 'support', 'analyst'] as $role) {
        $d = rk_json($ctl->queue(rk_req('GET', '/admin/api/recovery', $role), []));
        T::same(1, $d['total'], "$role sees the queue");
        T::same($role === 'owner' || $role === 'manager', count($d['rows'][0]['actions']) > 0, "$role is offered actions only with orders.recover");
    }
    T::same([false, false], [rk_json($ctl->queue(rk_req('GET', '/x', 'support'), []))['can']['recover'], rk_json($ctl->queue(rk_req('GET', '/x', 'support'), []))['can']['run_job']]);
    foreach (['support', 'analyst'] as $role) {
        T::throws(HttpException::class, fn() => $ctl->act(rk_req('POST', '/x', $role, ['action' => 'escalate', 'note' => 'please']), ['id' => (string) $o['id']]), 403);
        T::throws(HttpException::class, fn() => $ctl->saveSettings(rk_req('PUT', '/x', $role, ['max_attempts' => 3]), []), 403);
    }
    T::same('needs_review', rc_order((int) $o['id'])['recovery_status'], 'refused: nothing changed');
    $res = rk_json($ctl->act(rk_req('POST', '/x', 'manager', ['action' => 'escalate', 'note' => 'please look']), ['id' => (string) $o['id']]));
    T::same(['Escalated.', 'escalated'], [$res['message'], $res['order']['recovery_status']]);
    T::same(1, $res['summary']['counts']['review'] ?? 0);
    T::same(3, rk_json($ctl->saveSettings(rk_req('PUT', '/x', 'manager', ['max_attempts' => 3]), []))['policy']['max_attempts']);
    $e = T::throws(HttpException::class, fn() => $ctl->saveSettings(rk_req('PUT', '/x', 'owner', ['max_attempts' => 11]), []), 422);
    T::true(isset($e->extra['fields']['max_attempts']));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'recovery.settings_changed'"));
});

T::test('permissions: the same actions through the order drawer follow the role — manager yes, support / analyst no — and the buttons are only the ones allowed', function () {
    ['gid' => $gid] = ff_setup();
    $o = rc_unknown($gid, 'drawer_one');
    $ctl = new OrdersController();
    $actions = static fn(string $role): array => rk_json($ctl->show(rk_req('GET', '/x', $role), ['id' => (string) $o['id']]))['order']['actions'];
    T::same(['retry_submit', 'link_provider_order', 'move_to_manual', 'escalate', 'resolve_review', 'cancel', 'refund'], array_values(array_diff($actions('manager'), ['note'])));
    T::same([], $actions('support'));
    T::same([], $actions('analyst'));
    foreach (['support', 'analyst'] as $role) {
        $e = T::throws(HttpException::class, fn() => $ctl->act(rk_req('POST', '/x', $role, ['action' => 'escalate', 'note' => 'nope']), ['id' => (string) $o['id']]), 403);
        T::same('forbidden', $e->errorCode);
    }
    $d = rk_json($ctl->act(rk_req('POST', '/x', 'manager', ['action' => 'escalate', 'note' => 'via the drawer']), ['id' => (string) $o['id']]));
    T::same('Escalated.', $d['message']);
    T::same(['escalated', 'NEEDS_REVIEW'], [$d['detail']['order']['recovery_status'], $d['detail']['order']['state']]);
    T::same(['unknown', 'review'], $d['detail']['order']['recovery']['categories']);
    $list = rk_json($ctl->list(rk_req('GET', '/x', 'owner'), []));
    T::same('NEEDS_REVIEW', array_column($list['rows'], 'state', 'public_id')[$o['public_id']], 'the list speaks the standard vocabulary too');
    T::same(true, $list['can']['recover']);
});

T::test('jobs: "run now" and the health list are for roles with jobs.run / jobs.view; a manual run is the same recorded run, audited and rate-limited', function () {
    pf_setup();
    $ctl = new JobsController();
    T::same(['recovery', 'payments'], array_values(array_intersect(['recovery', 'payments'], array_column(rk_json($ctl->list(rk_req('GET', '/x', 'support'), []))['jobs'], 'job'))));
    T::throws(HttpException::class, fn() => $ctl->run(rk_req('POST', '/x', 'support'), ['job' => 'recovery']), 403);
    T::throws(HttpException::class, fn() => $ctl->run(rk_req('POST', '/x', 'analyst'), ['job' => 'recovery']), 403);
    T::throws(HttpException::class, fn() => $ctl->run(rk_req('POST', '/x', 'manager'), ['job' => 'no-such-job']), 404);
    $r = rk_json($ctl->run(rk_req('POST', '/x', 'manager', ['dry_run' => true]), ['job' => 'recovery']));
    T::same(['ok', true], [$r['run']['status'], $r['ok']]);
    $row = Db::one("SELECT trigger_source, dry_run FROM job_runs WHERE job = 'recovery' ORDER BY id DESC LIMIT 1");
    T::same(['admin', 1], [$row['trigger_source'], (int) $row['dry_run']]);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'job.run' AND entity_id = 'recovery'"));
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit('job-run', '1', 10, 600);
    }
    T::throws(HttpException::class, fn() => $ctl->run(rk_req('POST', '/x', 'manager'), ['job' => 'recovery']), 429);
});
