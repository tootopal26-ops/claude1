<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AdBudget;
use App\Services\Ads;
use App\Support\Clock;
use App\Support\Tz;

/** Advertising budget & forecast (Priority 3): the deferred Phase 8 screen over ad_campaign_settings. */

const AB_ADMIN = ['id' => 1, 'username' => 'tester', 'email' => 't@example.test', 'role' => 'owner'];
const AB_DAY0 = 1_800_000_000;

function ab_reset(): void
{
    T::freshDb();
    Tz::set('Asia/Kolkata');
    Clock::setFixed(AB_DAY0);
}

function ab_req(): Request { return Request::fake('PUT', '/admin/api/ad-budget'); }

/** A real PAID order credited to $campaign (or no campaign when '') on $paidDay. */
function ab_order(int $amountPaise, string $paidDay, string $campaign = ''): int
{
    $svc = Db::one("SELECT s.*, p.slug FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.code = 'ig-followers-50'");
    static $n = 0;
    $n++;
    $orderId = Db::insert('orders', [
        'public_id' => 'SMB-AB' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'access_token' => bin2hex(random_bytes(16)), 'service_id' => $svc['id'], 'platform_id' => $svc['platform_id'],
        'platform_slug' => $svc['slug'], 'service_code' => 'ig-followers-50', 'service_title' => $svc['title'], 'service_type' => $svc['type'], 'mode' => 'MANUAL', 'quantity' => 100,
        'amount_paise' => $amountPaise, 'target' => '@x', 'target_url' => 'https://www.instagram.com/x/', 'status' => 'COMPLETED', 'payment_status' => 'success',
        'refunded_paise' => 0, 'estimated_cost_paise' => 0, 'provider_cost_paise' => 0, 'created_day' => $paidDay, 'paid_day' => $paidDay,
        'paid_at' => Clock::sql(Tz::dayStart($paidDay) + 36000), 'created_at' => Clock::sql(Tz::dayStart($paidDay) + 35000), 'updated_at' => Clock::sql(),
    ]);
    Db::insert('campaign_attribution', [
        'order_id' => $orderId, 'model' => 'last_non_direct', 'window_days' => 7, 'touch_kind' => $campaign !== '' ? 'campaign' : 'direct',
        'utm_campaign' => $campaign !== '' ? $campaign : null, 'source' => $campaign !== '' ? 'meta' : 'direct', 'touch_at' => Clock::sql(), 'attributed_at' => Clock::sql(),
    ]);
    return $orderId;
}

/** $n qualified visitor-days on $day credited to $campaign (or '' = none). */
function ab_visitors(int $n, string $day, string $campaign = '', int $cpc = 108): void
{
    for ($i = 0; $i < $n; $i++) {
        $vid = Db::insert('visitors', ['anon_id' => bin2hex(random_bytes(16)), 'first_seen_at' => Clock::sql(), 'last_seen_at' => Clock::sql()]);
        Db::insert('visitor_days', [
            'visitor_id' => $vid, 'day' => $day, 'qualified' => 1, 'qualified_at' => Clock::sql(Tz::dayStart($day) + 40000),
            'cpc_paise' => $cpc, 'source' => 'direct', 'device_type' => 'mobile', 'created_at' => Clock::sql(Tz::dayStart($day) + 39000),
            'utm_campaign' => $campaign !== '' ? $campaign : null,
        ]);
    }
}

function ab_spend(int $amountPaise, string $day, string $campaign = ''): void
{
    Db::insert('ad_daily_spend', [
        'spend_day' => $day, 'campaign' => $campaign, 'platform' => 'meta', 'amount_paise' => $amountPaise, 'gst_paise' => 0,
        'currency' => 'INR', 'source' => 'manual', 'entered_by' => AB_ADMIN['id'], 'imported_at' => Clock::sql(),
    ]);
}

// ═════════════════════════ default / override precedence ═════════════════════════

T::test('a fresh install has no cap', function () {
    ab_reset();
    $c = AdBudget::current();
    T::same(['daily_budget_paise' => null, 'monthly_budget_paise' => null, 'source' => 'none', 'note' => null, 'period_start' => null, 'period_end' => null], $c);
});

T::test('save() on the default scope persists, is read back, and is audited', function () {
    ab_reset();
    $c = AdBudget::save(['scope' => 'default', 'daily_budget' => '500', 'monthly_budget' => '12000', 'note' => 'steady spend'], ab_req(), AB_ADMIN);
    T::same(50_000, $c['daily_budget_paise']);
    T::same(1_200_000, $c['monthly_budget_paise']);
    T::same('default', $c['source']);
    T::same(50_000, AdBudget::current()['daily_budget_paise']);
    $log = Db::one("SELECT * FROM audit_logs WHERE action = 'settings.ad_budget' ORDER BY id DESC LIMIT 1");
    T::true($log !== null);
});

T::test('saving a new default supersedes the old one; history keeps both', function () {
    ab_reset();
    AdBudget::save(['scope' => 'default', 'daily_budget' => '500'], ab_req(), AB_ADMIN);
    AdBudget::save(['scope' => 'default', 'daily_budget' => '900'], ab_req(), AB_ADMIN);
    T::same(90_000, AdBudget::current()['daily_budget_paise']);
    T::same(2, (int) Db::val("SELECT COUNT(*) FROM ad_campaign_settings WHERE kind = 'default'"));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM ad_campaign_settings WHERE kind = 'default' AND revoked_at IS NULL"));
});

T::test('an override applies only for its dates and wins over the default while it is active', function () {
    ab_reset();
    $today = Tz::today();
    AdBudget::save(['scope' => 'default', 'daily_budget' => '500'], ab_req(), AB_ADMIN);
    AdBudget::save(['scope' => 'override', 'period_start' => $today, 'period_end' => Tz::addDays($today, 2), 'daily_budget' => '5000', 'note' => 'festival sale'], ab_req(), AB_ADMIN);
    $c = AdBudget::current();
    T::same('override', $c['source']);
    T::same(500_000, $c['daily_budget_paise'], 'the override, not the ₹500 default');
    T::same('festival sale', $c['note']);
});

T::test('a second override that overlaps the first supersedes only that override, never the default', function () {
    ab_reset();
    $today = Tz::today();
    AdBudget::save(['scope' => 'default', 'daily_budget' => '500'], ab_req(), AB_ADMIN);
    AdBudget::save(['scope' => 'override', 'period_start' => $today, 'period_end' => Tz::addDays($today, 5), 'daily_budget' => '1000'], ab_req(), AB_ADMIN);
    // the second override starts the day the first one's revoked range still covers "today", so today itself is live under the first…
    T::same(100_000, AdBudget::current()['daily_budget_paise'], 'still the first override, before the second is saved');
    AdBudget::save(['scope' => 'override', 'period_start' => Tz::addDays($today, 2), 'period_end' => Tz::addDays($today, 8), 'daily_budget' => '2000'], ab_req(), AB_ADMIN);

    T::same(2, (int) Db::val("SELECT COUNT(*) FROM ad_campaign_settings WHERE kind = 'override'"), 'both overrides are kept, nothing deleted');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM ad_campaign_settings WHERE kind = 'override' AND revoked_at IS NOT NULL"), 'the overlapping first override was superseded');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM ad_campaign_settings WHERE kind = 'default' AND revoked_at IS NULL"), 'the default itself was never touched');
    // today is no longer covered by any override (the first was revoked, the second starts later) — the default applies again
    T::same('default', AdBudget::current()['source']);
    T::same(50_000, AdBudget::current()['daily_budget_paise']);
});

T::test('validation: an unknown scope, an override with no dates, a reversed/oversized range, and an amount over the cap are all refused', function () {
    ab_reset();
    $today = Tz::today();
    $e1 = T::throws(HttpException::class, fn() => AdBudget::save(['scope' => 'whenever', 'daily_budget' => '100'], ab_req(), AB_ADMIN), 422);
    T::true(isset($e1->extra['fields']['scope']));

    $e2 = T::throws(HttpException::class, fn() => AdBudget::save(['scope' => 'override', 'daily_budget' => '100'], ab_req(), AB_ADMIN), 422);
    T::true(isset($e2->extra['fields']['period_start']));

    $e3 = T::throws(HttpException::class, fn() => AdBudget::save(['scope' => 'override', 'period_start' => $today, 'period_end' => Tz::addDays($today, -1), 'daily_budget' => '100'], ab_req(), AB_ADMIN), 422);
    T::true(isset($e3->extra['fields']['period_end']), 'end before start');

    $e4 = T::throws(HttpException::class, fn() => AdBudget::save(['scope' => 'default', 'daily_budget' => '99999999'], ab_req(), AB_ADMIN), 422);
    T::true(isset($e4->extra['fields']['daily_budget']), 'over the daily cap');

    $e5 = T::throws(HttpException::class, fn() => AdBudget::save(['scope' => 'default', 'monthly_budget' => '0'], ab_req(), AB_ADMIN), 422);
    T::true(isset($e5->extra['fields']['monthly_budget']), 'zero is not a valid amount — leave it blank for no cap instead');

    T::same('none', AdBudget::current()['source'], 'nothing was saved');
});

T::test('reset() explicitly removes every cap, audited like any other save', function () {
    ab_reset();
    AdBudget::save(['scope' => 'default', 'daily_budget' => '500', 'monthly_budget' => '9000'], ab_req(), AB_ADMIN);
    $r = AdBudget::reset(ab_req(), AB_ADMIN);
    T::same(null, $r['daily_budget_paise']);
    T::same(null, $r['monthly_budget_paise']);
    T::same('default', $r['source']);
});

T::test('revokeOverride() ends the active override early; the default underneath applies again; refuses cleanly when none is active', function () {
    ab_reset();
    $today = Tz::today();
    T::throws(HttpException::class, fn() => AdBudget::revokeOverride(ab_req(), AB_ADMIN), 409);

    AdBudget::save(['scope' => 'default', 'daily_budget' => '500'], ab_req(), AB_ADMIN);
    AdBudget::save(['scope' => 'override', 'period_start' => $today, 'period_end' => Tz::addDays($today, 5), 'daily_budget' => '5000'], ab_req(), AB_ADMIN);
    $after = AdBudget::revokeOverride(ab_req(), AB_ADMIN);
    T::same('default', $after['source']);
    T::same(50_000, $after['daily_budget_paise']);
});

T::test('history() lists both kinds, newest first', function () {
    ab_reset();
    AdBudget::save(['scope' => 'default', 'daily_budget' => '500'], ab_req(), AB_ADMIN);
    AdBudget::save(['scope' => 'override', 'period_start' => Tz::today(), 'period_end' => Tz::addDays(Tz::today(), 3), 'daily_budget' => '900'], ab_req(), AB_ADMIN);
    $h = AdBudget::history();
    T::same(2, count($h));
    T::same('override', $h[0]['kind']);
    T::same('default', $h[1]['kind']);
});

// ═════════════════════════ forecast ═════════════════════════

T::test('forecast() with no history at all falls back to the configured CPC and names visitors only (no invented conversion)', function () {
    ab_reset();
    Ads::update('2.00', 3, 5, ab_req(), AB_ADMIN); // CPC ₹2.00
    $f = AdBudget::forecast(100_000_00); // ₹1,00,000
    T::same(50_000, $f['qualified_visitors'], '₹1,00,000 ÷ ₹2.00');
    T::same(null, $f['customers'], 'no conversion data exists yet — never guessed');
    T::same(null, $f['revenue_paise']);
    T::false($f['basis']['cost_per_qualified_is_real']);
});

T::test('forecast() prefers real recent spend and conversion data over the CPC estimate, and the maths is internally consistent', function () {
    ab_reset();
    $today = Tz::today();
    for ($d = 0; $d < 5; $d++) {
        $day = Tz::addDays($today, -$d);
        ab_visitors(20, $day);              // 100 qualified visitors over the window
        ab_spend(400_00, $day);             // ₹400/day actual spend over 20 visitors/day → ₹20/qualified visitor
    }
    for ($i = 0; $i < 10; $i++) {
        ab_order(500_00, $today);          // 10 customers × ₹500 = ₹5,000 revenue, 10% conversion
    }
    $f = AdBudget::forecast(200_00); // a ₹200 budget → 10 qualified visitors at ₹20 each
    T::same(10, $f['qualified_visitors']);
    T::same(1, $f['customers'], '10% conversion of 10 visitors, floored');
    T::true($f['basis']['cost_per_qualified_is_real']);
    T::same(2000, $f['basis']['cost_per_qualified_paise'], '₹20.00 per qualified visitor from real spend');
    T::same($f['customers'] * $f['basis']['revenue_per_customer_paise'], $f['revenue_paise']);
});

T::test('forecast() scoped to one campaign uses only that campaign\'s own unit economics', function () {
    ab_reset();
    $today = Tz::today();
    ab_visitors(50, $today, 'diwali');
    ab_spend(1000_00, $today, 'diwali');     // ₹1,000 ÷ 50 = ₹20/qualified visitor for "diwali"
    ab_visitors(50, $today, 'other');
    ab_spend(5000_00, $today, 'other');      // ₹100/qualified visitor for "other" — must not leak into "diwali"'s forecast
    for ($i = 0; $i < 5; $i++) ab_order(200_00, $today, 'diwali');

    $f = AdBudget::forecast(1000_00, 'diwali');
    T::same(50, $f['qualified_visitors'], '₹1,000 ÷ ₹20, the diwali rate only');
    T::same('diwali', $f['campaign']);
});

T::test('forecast() refuses a negative budget', function () {
    ab_reset();
    T::throws(HttpException::class, fn() => AdBudget::forecast(-1), 422);
});

// ═════════════════════════ concurrency (DB integrity review, Priority 5) ═════════════════════════

T::test('save(): four admins saving the default budget at the same instant never leave two live rows', function () {
    ab_reset();
    $go = sys_get_temp_dir() . '/smb-go-budget-' . getmypid();
    @unlink($go);
    $procs = [];
    $pipes = [];
    $tried = [];
    for ($i = 0; $i < 4; $i++) {
        $amount = (string) (100 + $i);
        $tried[] = ((int) $amount) * 100;
        $procs[] = proc_open(['php', SMB_ROOT . '/tests/fixtures/ad_budget_worker.php', $go, $amount], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    usleep(800_000);
    touch($go);
    $out = [];
    foreach ($procs as $i => $proc) {
        $out[] = trim((string) stream_get_contents($pipes[$i][1]));
        proc_close($proc);
    }
    @unlink($go);

    T::same(4, (int) Db::val("SELECT COUNT(*) FROM ad_campaign_settings WHERE kind = 'default'"), 'every save created its own row — ' . json_encode($out));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM ad_campaign_settings WHERE kind = 'default' AND revoked_at IS NULL"), 'exactly one survives as live — never two, never zero');
    T::true(in_array(AdBudget::current()['daily_budget_paise'], $tried, true), 'the live budget is one of the four values actually saved, not a mix');
});
