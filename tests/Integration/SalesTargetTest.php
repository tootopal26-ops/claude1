<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\SalesTarget;
use App\Support\Clock;
use App\Support\Tz;

/** Sales Target: a periodic revenue goal and real pacing against it (Priority 3). */

const ST_ADMIN = ['id' => 1, 'username' => 'tester', 'email' => 't@example.test', 'role' => 'owner'];
const ST_DAY0 = 1_800_000_000; // a fixed "now" (UTC) for deterministic days

function st_reset(): void
{
    T::freshDb();
    Tz::set('Asia/Kolkata');
    Clock::setFixed(ST_DAY0);
}

function st_req(): Request { return Request::fake('PUT', '/admin/api/sales-target'); }

/** Insert a synthetic PAID order on $paidDay, bypassing checkout so the test controls the exact revenue. */
function st_order(int $amountPaise, string $paidDay, int $refunded = 0): int
{
    $svc = Db::one("SELECT s.*, p.slug FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.code = 'ig-followers-50'");
    static $n = 0;
    $n++;
    return Db::insert('orders', [
        'public_id' => 'SMB-ST' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'access_token' => bin2hex(random_bytes(16)), 'service_id' => $svc['id'], 'platform_id' => $svc['platform_id'],
        'platform_slug' => $svc['slug'], 'service_code' => 'ig-followers-50', 'service_title' => $svc['title'], 'service_type' => $svc['type'], 'mode' => 'MANUAL', 'quantity' => 100,
        'amount_paise' => $amountPaise, 'target' => '@x', 'target_url' => 'https://www.instagram.com/x/', 'status' => 'COMPLETED', 'payment_status' => $refunded >= $amountPaise ? 'refunded' : 'success',
        'refunded_paise' => $refunded, 'estimated_cost_paise' => 0, 'provider_cost_paise' => 0, 'created_day' => $paidDay, 'paid_day' => $paidDay,
        'paid_at' => Clock::sql(Tz::dayStart($paidDay) + 36000), 'created_at' => Clock::sql(Tz::dayStart($paidDay) + 35000), 'updated_at' => Clock::sql(),
    ]);
}

// ═════════════════════════ validation & persistence ═════════════════════════

T::test('no target is set on a fresh install', function () {
    st_reset();
    T::same(null, SalesTarget::current());
    T::false(SalesTarget::progress()['has_target']);
});

T::test('set() persists, is read back by current(), and is audited', function () {
    st_reset();
    $today = Tz::today();
    $end = Tz::addDays($today, 29);
    $p = SalesTarget::set(['period_start' => $today, 'period_end' => $end, 'target' => '50000', 'note' => 'Diwali push'], st_req(), ST_ADMIN);
    T::true($p['has_target']);
    T::same($today, $p['target']['period_start']);
    T::same($end, $p['target']['period_end']);
    T::same(5_000_000, $p['target']['target_paise']);
    T::same('Diwali push', $p['target']['note']);

    $cur = SalesTarget::current();
    T::same(5_000_000, $cur['target_paise']);

    $log = Db::one("SELECT * FROM audit_logs WHERE action = 'settings.sales_target' ORDER BY id DESC LIMIT 1");
    T::true($log !== null, 'the change is audited');
});

T::test('validation: bad dates, end before start, an oversized span, a non-positive or huge target, and a long note are all refused', function () {
    st_reset();
    $today = Tz::today();
    $e1 = T::throws(HttpException::class, fn() => SalesTarget::set(['period_start' => 'not-a-date', 'period_end' => $today, 'target' => '100'], st_req(), ST_ADMIN), 422);
    T::true(isset($e1->extra['fields']['period_start']));

    $e2 = T::throws(HttpException::class, fn() => SalesTarget::set(['period_start' => $today, 'period_end' => Tz::addDays($today, -1), 'target' => '100'], st_req(), ST_ADMIN), 422);
    T::true(isset($e2->extra['fields']['period_end']), 'end before start');

    $e3 = T::throws(HttpException::class, fn() => SalesTarget::set(['period_start' => $today, 'period_end' => Tz::addDays($today, 400), 'target' => '100'], st_req(), ST_ADMIN), 422);
    T::true(isset($e3->extra['fields']['period_end']), 'span over the 366-day cap');

    $e4 = T::throws(HttpException::class, fn() => SalesTarget::set(['period_start' => $today, 'period_end' => $today, 'target' => '0'], st_req(), ST_ADMIN), 422);
    T::true(isset($e4->extra['fields']['target']), 'zero target');

    $e5 = T::throws(HttpException::class, fn() => SalesTarget::set(['period_start' => $today, 'period_end' => $today, 'target' => '99999999999'], st_req(), ST_ADMIN), 422);
    T::true(isset($e5->extra['fields']['target']), 'absurdly large target');

    $e6 = T::throws(HttpException::class, fn() => SalesTarget::set(['period_start' => $today, 'period_end' => $today, 'target' => '100', 'note' => str_repeat('x', 256)], st_req(), ST_ADMIN), 422);
    T::true(isset($e6->extra['fields']['note']), 'note over 255 characters');

    T::same(null, SalesTarget::current(), 'none of the rejected attempts created anything');
});

T::test('a new target that overlaps an existing one supersedes it (revoked, never deleted); a non-overlapping one does not', function () {
    st_reset();
    $today = Tz::today();
    $first = SalesTarget::set(['period_start' => $today, 'period_end' => Tz::addDays($today, 29), 'target' => '10000'], st_req(), ST_ADMIN)['target']['id'];
    $second = SalesTarget::set(['period_start' => Tz::addDays($today, 10), 'period_end' => Tz::addDays($today, 40), 'target' => '20000'], st_req(), ST_ADMIN)['target']['id'];

    T::true(Db::val('SELECT revoked_at IS NOT NULL FROM sales_targets WHERE id = ?', [$first]) == 1, 'the overlapping first target was revoked');
    T::same(null, Db::val('SELECT revoked_at FROM sales_targets WHERE id = ?', [$second]), 'the new one is live');
    T::same(2, (int) Db::val('SELECT COUNT(*) FROM sales_targets'), 'history is kept, nothing deleted');

    // a target entirely after the live one's period does not touch it
    $third = SalesTarget::set(['period_start' => Tz::addDays($today, 100), 'period_end' => Tz::addDays($today, 110), 'target' => '5000'], st_req(), ST_ADMIN);
    T::same(null, Db::val('SELECT revoked_at FROM sales_targets WHERE id = ?', [$second]), 'the non-overlapping second target is untouched');
});

T::test('revoke() ends the current target early; refuses cleanly when there is none', function () {
    st_reset();
    T::throws(HttpException::class, fn() => SalesTarget::revoke(st_req(), ST_ADMIN), 409);

    $today = Tz::today();
    SalesTarget::set(['period_start' => $today, 'period_end' => Tz::addDays($today, 10), 'target' => '10000'], st_req(), ST_ADMIN);
    $after = SalesTarget::revoke(st_req(), ST_ADMIN);
    T::false($after['has_target']);
    T::same(null, SalesTarget::current());
});

T::test('history() lists every target, newest first, revoked ones included', function () {
    st_reset();
    $today = Tz::today();
    SalesTarget::set(['period_start' => $today, 'period_end' => Tz::addDays($today, 5), 'target' => '1000'], st_req(), ST_ADMIN);
    SalesTarget::set(['period_start' => Tz::addDays($today, 2), 'period_end' => Tz::addDays($today, 8), 'target' => '2000'], st_req(), ST_ADMIN);
    $h = SalesTarget::history();
    T::same(2, count($h));
    T::same(2000_00, $h[0]['target_paise']);
    T::true($h[1]['revoked_at'] !== null);
});

// ═════════════════════════ pacing math ═════════════════════════

T::test('progress(): not started yet — zero actual, no projection, the average pace that would be needed is shown', function () {
    st_reset();
    $today = Tz::today();
    $p = SalesTarget::set(['period_start' => Tz::addDays($today, 5), 'period_end' => Tz::addDays($today, 34), 'target' => '30000'], st_req(), ST_ADMIN);
    T::same('not_started', $p['status']);
    T::same(0, $p['actual_paise']);
    T::same(0, $p['elapsed_days']);
    T::same(30, $p['total_days']);
    T::same(null, $p['pace_label']);
    T::same(3_000_000, $p['target']['target_paise']);
    T::same(100_000, $p['required_daily_pace_paise'], '₹3,000,000 ÷ 30 days');
});

T::test('progress(): in progress — real revenue from analytics_daily drives actual/pct/projection/pace, matching Dashboard\'s own numbers', function () {
    st_reset();
    $today = Tz::today();
    // a 10-day period, 4 days elapsed (today is day 4): ₹1,000 paid each of the first 3 days, nothing yet today
    $start = Tz::addDays($today, -3);
    $end = Tz::addDays($today, 6);
    st_order(100_000, Tz::addDays($start, 0));
    st_order(100_000, Tz::addDays($start, 1));
    st_order(100_000, Tz::addDays($start, 2));
    $p = SalesTarget::set(['period_start' => $start, 'period_end' => $end, 'target' => '10000'], st_req(), ST_ADMIN);

    T::same('in_progress', $p['status']);
    T::same(4, $p['elapsed_days']);
    T::same(10, $p['total_days']);
    T::same(300_000, $p['actual_paise'], 'three ₹1,000 orders, nothing today yet');
    T::same(300, $p['pct_of_target_tenths'], '30.0% of the ₹10,000 target');
    T::same(75_000, $p['daily_pace_paise'], '₹3,000 over 4 elapsed days');
    T::same(750_000, $p['projected_paise'], '₹750/day average × 10 days');
    T::same('behind', $p['pace_label'], 'projected ₹7,500 is well under the ₹10,000 target');
    T::same(700_000, $p['remaining_to_target_paise']);
    T::same(6, $p['remaining_days']);
});

T::test('progress(): a target comfortably on pace reads "ahead" or "on_track" correctly, not "behind"', function () {
    st_reset();
    $today = Tz::today();
    $start = Tz::addDays($today, -1);
    $end = Tz::addDays($today, 8);
    st_order(1_100_000, $start); // ₹11,000 already, before a ₹10,000 / 10-day target even reaches its 2nd elapsed day
    $p = SalesTarget::set(['period_start' => $start, 'period_end' => $end, 'target' => '10000'], st_req(), ST_ADMIN);
    T::same('ahead', $p['pace_label']);
    T::true($p['projected_pct_tenths'] >= 1050);
    T::same(0, $p['required_daily_pace_paise'], 'the target is already exceeded — nothing more is required');
});

T::test('progress(): a period that has ended reports a final result, never a projection', function () {
    st_reset();
    $today = Tz::today();
    $start = Tz::addDays($today, -10);
    $end = Tz::addDays($today, -3);
    st_order(400_000, Tz::addDays($start, 0));
    $p = SalesTarget::set(['period_start' => $start, 'period_end' => $end, 'target' => '10000'], st_req(), ST_ADMIN);
    T::same('ended', $p['status']);
    T::same(400_000, $p['actual_paise']);
    T::same($p['actual_paise'], $p['projected_paise'], 'ended: projected is simply what happened');
    T::same(0, $p['remaining_days']);
    T::same(null, $p['required_daily_pace_paise']);
});

T::test('progress() never disagrees with the dashboard\'s own revenue figure for the same days', function () {
    st_reset();
    $today = Tz::today();
    $start = Tz::addDays($today, -2);
    st_order(250_000, $start);
    st_order(150_000, Tz::addDays($start, 1), 50_000); // a partial refund reduces net revenue
    $p = SalesTarget::set(['period_start' => $start, 'period_end' => Tz::addDays($today, 5), 'target' => '100000'], st_req(), ST_ADMIN);
    $direct = \App\Services\Analytics::sum(\App\Services\Analytics::rollup($start, $today))['revenue_paise'];
    T::same($direct, $p['actual_paise']);
    T::same(350_000, $p['actual_paise'], '₹2,500 + (₹1,500 − ₹500 refund)');
});
