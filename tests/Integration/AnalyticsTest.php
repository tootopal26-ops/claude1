<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Request;
use App\Services\Ads;
use App\Services\Analytics;
use App\Services\Dashboard;
use App\Services\Tracking;
use App\Support\Clock;
use App\Support\Profit;
use App\Support\Tz;

const AN_DAY0 = 1_800_000_000;   // a fixed "now" (UTC) for deterministic days

function an_reset(): void
{
    T::freshDb();
    Tz::set('Asia/Kolkata');
    Clock::setFixed(AN_DAY0);
}

/** Insert a synthetic PAID order (bypasses checkout so tests control every number). */
function an_order(string $mode, int $amount, int $cost, ?string $paidDay = null, int $refunded = 0, string $status = 'COMPLETED', string $code = 'ig-followers-50', ?string $createdDay = null): int
{
    $svc = Db::one('SELECT s.*, p.slug FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.code = ?', [$code]);
    $paidDay ??= Tz::today();
    $createdDay ??= $paidDay;
    static $n = 0;
    $n++;
    $orderId = Db::insert('orders', [
        'public_id' => 'SMB-T' . str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'access_token' => bin2hex(random_bytes(16)), 'service_id' => $svc['id'], 'platform_id' => $svc['platform_id'],
        'platform_slug' => $svc['slug'], 'service_code' => $code, 'service_title' => $svc['title'], 'service_type' => $svc['type'], 'mode' => $mode, 'quantity' => 100,
        'amount_paise' => $amount, 'target' => '@x', 'target_url' => 'https://www.instagram.com/x/', 'status' => $status, 'payment_status' => $refunded >= $amount ? 'refunded' : 'success',
        'refunded_paise' => $refunded, 'estimated_cost_paise' => $cost, 'provider_cost_paise' => $cost, 'created_day' => $createdDay, 'paid_day' => $paidDay,
        'paid_at' => Clock::sql(Tz::dayStart($paidDay) + 36000), 'created_at' => Clock::sql(Tz::dayStart($createdDay) + 35000), 'updated_at' => Clock::sql(),
    ]);
    Db::insert('payments', [
        'ref' => bin2hex(random_bytes(16)), 'order_id' => $orderId, 'provider' => 'alpha_upi', 'gateway_order_id' => 'G' . $orderId, 'amount_paise' => $amount,
        'status' => $refunded >= $amount ? 'refunded' : ($refunded > 0 ? 'partially_refunded' : 'success'), 'refunded_paise' => $refunded, 'expires_at' => Clock::sql(),
        'paid_at' => Clock::sql(Tz::dayStart($paidDay) + 36000), 'paid_day' => $paidDay, 'created_day' => $createdDay, 'created_at' => Clock::sql(Tz::dayStart($createdDay) + 35000), 'updated_at' => Clock::sql(),
    ]);
    return $orderId;
}

/** Insert $n visitor-days for $day, $qualified of them charged at $cpc paise. */
function an_visitors(int $n, int $qualified, string $day, int $cpc = 108, string $source = 'direct', string $device = 'mobile'): void
{
    for ($i = 0; $i < $n; $i++) {
        $vid = Db::insert('visitors', ['anon_id' => bin2hex(random_bytes(16)), 'first_seen_at' => Clock::sql(), 'last_seen_at' => Clock::sql()]);
        $q = $i < $qualified;
        Db::insert('visitor_days', [
            'visitor_id' => $vid, 'day' => $day, 'qualified' => $q ? 1 : 0, 'qualified_at' => $q ? Clock::sql(Tz::dayStart($day) + 40000) : null,
            'cpc_paise' => $q ? $cpc : 0, 'source' => $source, 'device_type' => $device, 'created_at' => Clock::sql(Tz::dayStart($day) + 39000),
        ]);
    }
}

// ═════════════════════════ profit & dashboard numbers ═════════════════════════

T::test('dashboard: the spec example — revenue ₹1000, SMM ₹300, ads ₹108 → net ₹592, margin 59.2%', function () {
    an_reset();
    $today = Tz::today();
    for ($i = 0; $i < 5; $i++) {
        an_order('AUTO', 10000, 6000);       // 5 × ₹100, provider cost ₹60 each  → ₹500 rev, ₹300 SMM
        an_order('MANUAL', 10000, 9999);     // 5 × ₹100 manual; provider cost must be IGNORED → ₹500 rev, 100 % profit
    }
    an_visitors(100, 100, $today);           // 100 qualified × ₹1.08 = ₹108

    $d = Dashboard::build(['range' => 'today']);
    T::same(100000, $d['kpis']['today']['revenue']['value']);
    T::same(30000, $d['kpis']['today']['smm']['value'], 'manual orders contribute no SMM cost');
    T::same(10800, $d['kpis']['today']['ads']['value']);
    T::same(59200, $d['kpis']['today']['profit']['value']);
    T::same(70000, $d['kpis']['today']['gross_profit']);
    $b = $d['breakdown'];
    T::same(['revenue' => 100000, 'smm' => 30000, 'ads' => 10800, 'gross' => 70000, 'net' => 59200, 'margin_tenths' => 592, 'margin' => '59.2'], $b);
    T::same(10, $d['kpis']['today']['orders']['value']);
    T::same(10, $d['kpis']['today']['payments']['value']);
    T::same(100, $d['visitors']['today']['visitors']);
    // AUTO vs MANUAL
    T::same(['orders' => 5, 'revenue' => 50000, 'profit' => 20000], $d['performance']['auto_vs_manual']['auto']);
    T::same(['orders' => 5, 'revenue' => 50000, 'profit' => 50000], $d['performance']['auto_vs_manual']['manual']);
    T::same(10000, $d['performance']['aov_paise']);
    T::same(5920, $d['performance']['avg_profit_paise']);
    // the SQL fragments and the PHP rule agree
    T::same(70000, Analytics::grossProfitDirect($today, $today));
    T::same(70000, Profit::breakdown(100000, 30000, 0)['gross']);
});

T::test('dashboard: ad spend = qualified visitors × CPC, never double counts SMM, and uses the CPC of the time', function () {
    an_reset();
    $today = Tz::today();
    $yesterday = Tz::addDays($today, -1);
    an_visitors(10, 10, $yesterday, 108);       // 10 × ₹1.08 = ₹10.80 yesterday
    an_visitors(10, 4, $today, 150);            // CPC was raised to ₹1.50 today; only 4 qualified
    $d = Dashboard::build(['range' => '7d']);
    T::same(1080 + 600, $d['totals']['ads_spend_paise']);
    T::same(10, $d['kpis']['today']['visitors']['value'], 'today visitors');
    T::same(600, $d['kpis']['today']['ads']['value']);
    T::same(1080, $d['kpis']['today']['ads']['prev'], 'yesterday keeps its own CPC');
    T::same(20, $d['totals']['visitors']);
    T::same(14, $d['totals']['qualified_visitors']);
});

T::test('dashboard: refunds reduce revenue; a refunded AUTO order still carries its provider cost (a real loss)', function () {
    an_reset();
    an_order('AUTO', 9900, 2500, null, 9900, 'REFUNDED');
    an_order('MANUAL', 9900, 0, null, 3000, 'MANUAL_APPROVED');
    $d = Dashboard::build(['range' => 'today']);
    T::same(0 + 6900, $d['breakdown']['revenue']);
    T::same(2500, $d['breakdown']['smm']);
    T::same(4400, $d['breakdown']['gross']);
    T::same(2, $d['kpis']['today']['payments']['value']);
});

T::test('dashboard: filters narrow order numbers; ads are site-wide and flagged as non-attributable', function () {
    an_reset();
    $today = Tz::today();
    an_order('AUTO', 5000, 1000, null, 0, 'COMPLETED', 'ig-followers-50');
    an_order('MANUAL', 4900, 0, null, 0, 'COMPLETED', 'yt-likes-50k');
    an_visitors(5, 5, $today);
    $all = Dashboard::build(['range' => 'today']);
    T::same(9900, $all['breakdown']['revenue']);
    T::false($all['meta']['filtered']);
    $ig = Dashboard::build(['range' => 'today', 'platform' => 'instagram']);
    T::same(5000, $ig['breakdown']['revenue']);
    T::same(4000, $ig['breakdown']['net'], 'filtered net is shown BEFORE ads');
    T::same(0, $ig['breakdown']['ads']);
    T::false($ig['meta']['ads_attributable']);
    T::same(540, $ig['totals']['ads_spend_paise'], 'site-wide ad spend is still reported');
    T::same(5, $ig['visitors']['range']['visitors']);
    $man = Dashboard::build(['range' => 'today', 'mode' => 'MANUAL']);
    T::same(4900, $man['breakdown']['revenue']);
    T::same(9900, $all['kpis']['today']['revenue']['value'], 'KPI cards ignore filters');
    T::same(9900, $ig['kpis']['today']['revenue']['value']);
    T::throws(App\Core\HttpException::class, fn() => Dashboard::build(['range' => 'today', 'platform' => 'tiktok']), 422);
});

T::test('dashboard: custom ranges validate; series buckets (hour / day / week / month)', function () {
    an_reset();
    $today = Tz::today();
    an_order('MANUAL', 1000, 0, Tz::addDays($today, -40));
    an_order('MANUAL', 2000, 0, Tz::addDays($today, -2));
    T::same('hour', Dashboard::build(['range' => 'today'])['meta']['range']['bucket']);
    T::same(24, count(Dashboard::build(['range' => 'today'])['series']));
    T::same('day', Dashboard::build(['range' => '30d'])['meta']['range']['bucket']);
    T::same(30, count(Dashboard::build(['range' => '30d'])['series']));
    T::same('day', Dashboard::build(['range' => '90d'])['meta']['range']['bucket']);
    $c = Dashboard::build(['range' => 'custom', 'from' => Tz::addDays($today, -50), 'to' => $today]);
    T::same(51, count($c['series']));
    T::same(3000, $c['totals']['revenue_paise']);
    $swapped = Dashboard::build(['range' => 'custom', 'from' => $today, 'to' => Tz::addDays($today, -5)]);
    T::same(6, $swapped['meta']['range']['days'], 'reversed dates are corrected');
    $one = Dashboard::build(['range' => 'custom', 'from' => Tz::addDays($today, -2), 'to' => Tz::addDays($today, -2)]);
    T::same('hour', $one['meta']['range']['bucket']);
    T::same(2000, array_sum(array_column($one['series'], 'revenue')));
    foreach ([['range' => 'custom', 'from' => 'nope', 'to' => $today], ['range' => 'custom', 'from' => '2020-01-01', 'to' => $today], ['range' => 'forever']] as $bad) {
        T::throws(App\Core\HttpException::class, fn() => Dashboard::build($bad), 422);
    }
    $life = Dashboard::build(['range' => 'lifetime']);
    T::same(3000, $life['totals']['revenue_paise']);
    T::same(Tz::addDays($today, -40), $life['meta']['range']['from']);
});

// ═════════════════════════ rollup correctness ═════════════════════════

T::test('rollup: analytics_daily equals the direct computation, and updates after a refund (dirty marking)', function () {
    an_reset();
    $today = Tz::today();
    $d1 = Tz::addDays($today, -3);
    $d2 = Tz::addDays($today, -2);
    $id1 = an_order('AUTO', 9900, 2500, $d1);
    an_order('MANUAL', 4900, 0, $d1);
    an_order('MANUAL', 9900, 0, $d2);
    an_visitors(6, 3, $d1);
    an_visitors(2, 2, $d2);
    $range = [Tz::addDays($today, -5), $today];

    $roll = Analytics::rollup(...$range);
    $direct = Analytics::computeDays(...$range);
    T::same($direct, $roll, 'rollup == direct');
    T::same(1, (int) Db::val('SELECT dirty = 0 FROM analytics_daily WHERE day = ?', [$d1]));
    T::same(14800, $roll[$d1]['revenue_paise']);

    // refund the AUTO order fully through the real service → the day must be re-computed
    $admin = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test'];
    App\Services\Fulfilment::refund($id1, 9900, 'UPI-1', $admin, Request::fake('POST', '/x'));
    T::same(1, (int) Db::val('SELECT dirty FROM analytics_daily WHERE day = ?', [$d1]), 'refund marked the day dirty');
    $after = Analytics::rollup(...$range);
    T::same(Analytics::computeDays(...$range), $after, 'still equals the direct computation');
    T::same(4900, $after[$d1]['revenue_paise']);
    T::same(4900 - 2500, $after[$d1]['gross_profit_paise'], 'refunded AUTO: revenue gone, provider cost remains');
});

T::test('rollup: a day computed before it ended is recomputed once it is over', function () {
    an_reset();
    $today = Tz::today();
    an_order('MANUAL', 1000, 0, $today);
    Analytics::rollup(Tz::addDays($today, -1), $today);                 // stores nothing for today (live)
    // pretend yesterday's row was computed during yesterday itself, with data that has since changed
    $y = Tz::addDays($today, -1);
    Db::exec('INSERT INTO analytics_daily (day, revenue_paise, dirty, computed_at) VALUES (?, 111, 0, ?) ON DUPLICATE KEY UPDATE revenue_paise = 111, dirty = 0, computed_at = VALUES(computed_at)', [$y, Clock::sql(Tz::dayStart($y) + 3600)]);
    an_order('MANUAL', 5000, 0, $y);
    $r = Analytics::rollup($y, $y);
    T::same(5000, $r[$y]['revenue_paise'], 'stale (computed-before-day-end) row was refreshed');
});

// ═════════════════════════ visitor tracking ═════════════════════════

function tr_req(string $path, array $cookies = [], array $body = [], string $ip = '198.51.100.7', string $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/124 Mobile Safari/537.36'): Request
{
    return Request::fake('POST', $path, ['Content-Type' => 'application/json', 'User-Agent' => $ua, 'Referer' => 'http://127.0.0.1:8080/'], json_encode($body), $cookies, $ip);
}

function tr_hit(Request $req): array
{
    return Tracking::hit($req, $req->json());
}

/** @return array<string,string> cookies the browser would now hold */
function tr_cookies(array $result, array $jar = []): array
{
    foreach ($result['cookies'] as [$n, $v]) {
        $jar[$n] = $v;
    }
    return $jar;
}

T::test('tracking: first hit creates visitor+session; a refresh does NOT create another visitor', function () {
    an_reset();
    $r1 = tr_hit(tr_req('/api/track/hit', [], ['first' => true, 'path' => '/', 'ref' => 'https://l.instagram.com/?u=x']));
    T::true($r1['body']['tracked']);
    T::same(2, count($r1['cookies']));
    $jar = tr_cookies($r1);
    for ($i = 0; $i < 5; $i++) {          // five refreshes
        Clock::advance(2);
        $r = tr_hit(tr_req('/api/track/hit', $jar, ['first' => false, 'path' => '/', 'ref' => '']));
        T::true($r['body']['tracked']);
        T::same(0, count($r['cookies']), 'cookies are reused');
    }
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM visitors'));
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM visitor_sessions'));
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM visitor_days'));
    $v = Db::one('SELECT * FROM visitors');
    T::same('instagram', $v['first_source']);
    T::same('mobile', $v['device_type']);
    T::same('Chrome', $v['browser']);
    T::same('Android', $v['os']);
    T::same('l.instagram.com', $v['first_referrer_host'], 'only the host is kept, never the full referrer URL');
    T::false(array_key_exists('ip', $v), 'no IP address column on visitors');
});

T::test('tracking: qualified only after 3 s; refresh/return the same day never charges twice; CPC snapshot per day', function () {
    an_reset();
    $cpc = Ads::cpcPaise();
    T::same(108, $cpc);
    $jar = tr_cookies(tr_hit(tr_req('/api/track/hit', [], ['first' => true, 'path' => '/'])));
    $early = Tracking::qualify(tr_req('/api/track/qualify', $jar));
    T::false($early['qualified']);                       // immediately: not 3 s yet
    Clock::advance(1);
    T::false(Tracking::qualify(tr_req('/api/track/qualify', $jar))['qualified'], 'after 1 s');
    Clock::advance(3);
    $ok = Tracking::qualify(tr_req('/api/track/qualify', $jar));
    T::true($ok['qualified']);
    T::true($ok['charged']);
    T::same(108, (int) Db::val('SELECT cpc_paise FROM visitor_days'));
    // again, and after a refresh: no second charge
    T::true(Tracking::qualify(tr_req('/api/track/qualify', $jar))['already']);
    tr_hit(tr_req('/api/track/hit', $jar, ['first' => false, 'path' => '/']));
    T::same(108, (int) Db::val('SELECT SUM(cpc_paise) FROM visitor_days'));
    // a NEW session the same day (31 min later) qualifies but is free: one visitor = one click per day
    Clock::advance(31 * 60);
    $jar2 = $jar;
    $r = tr_hit(tr_req('/api/track/hit', $jar2, ['first' => false, 'path' => '/']));
    T::same(2, (int) Db::val('SELECT COUNT(*) FROM visitor_sessions'), 'idle 30 min → new session');
    $jar2 = tr_cookies($r, $jar2);
    Clock::advance(4);
    $q = Tracking::qualify(tr_req('/api/track/qualify', $jar2));
    T::true($q['qualified']);
    T::false($q['charged'], 'second session the same day is not charged again');
    T::same(108, (int) Db::val('SELECT SUM(cpc_paise) FROM visitor_days'));
    // CPC changes; the same visitor returns NEXT day and is charged the NEW cpc, history untouched
    App\Services\Ads::update('1.50', 3, '4', Request::fake('POST', '/x'), ['id' => 1, 'username' => 'root', 'email' => 'r@x.test']);
    Clock::advance(86400);
    $jar3 = tr_cookies(tr_hit(tr_req('/api/track/hit', $jar2, ['first' => false, 'path' => '/'])), $jar2);
    Clock::advance(4);
    T::true(Tracking::qualify(tr_req('/api/track/qualify', $jar3))['charged']);
    $rows = Db::col('SELECT cpc_paise FROM visitor_days ORDER BY day');
    T::same([108, 150], array_map('intval', $rows));
});

T::test('tracking: 10 distinct qualified visitors × ₹1.08 = ₹10.80 of ad spend on the dashboard', function () {
    an_reset();
    for ($i = 0; $i < 10; $i++) {
        $ip = '203.0.113.' . ($i + 1);
        $jar = tr_cookies(tr_hit(tr_req('/api/track/hit', [], ['first' => true, 'path' => '/'], $ip)));
        Clock::advance(4);
        Tracking::qualify(tr_req('/api/track/qualify', $jar, [], $ip));
        Clock::advance(-4);
    }
    // two more who leave before 3 s are visitors but cost nothing
    for ($i = 0; $i < 2; $i++) {
        tr_hit(tr_req('/api/track/hit', [], ['first' => true, 'path' => '/'], '203.0.113.' . (50 + $i)));
    }
    $d = Dashboard::build(['range' => 'today']);
    T::same(12, $d['visitors']['today']['visitors']);
    T::same(10, $d['visitors']['today']['qualified']);
    T::same(1080, $d['kpis']['today']['ads']['value']);
    T::same('10.80', App\Support\Money::plain($d['kpis']['today']['ads']['value']));
});

T::test('tracking: bots are ignored; blocked cookies never mint a visitor per reload; beacons are rate limited', function () {
    an_reset();
    $bot = tr_hit(tr_req('/api/track/hit', [], ['first' => true], '198.51.100.9', 'Googlebot/2.1 (+http://www.google.com/bot.html)'));
    T::false($bot['body']['tracked']);
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM visitors'));
    foreach (['HeadlessChrome/120', 'curl/8.0', 'Lighthouse'] as $ua) {
        T::false(tr_hit(tr_req('/api/track/hit', [], ['first' => true], '198.51.100.9', 'Mozilla/5.0 ' . $ua))['body']['tracked'], $ua);
    }
    // cookies blocked: the page says first=false on reloads and has no cookie → nothing is created
    $r = tr_hit(tr_req('/api/track/hit', [], ['first' => false, 'path' => '/']));
    T::false($r['body']['tracked']);
    T::same('no_cookie', $r['body']['reason']);
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM visitors'));
    // flood: new visitors per IP per hour are capped
    $created = 0;
    for ($i = 0; $i < 60; $i++) {
        try {
            $created += tr_hit(tr_req('/api/track/hit', [], ['first' => true, 'path' => '/'], '192.0.2.77'))['body']['tracked'] ? 1 : 0;
        } catch (App\Core\HttpException $e) {
            T::same(429, $e->status);
            break;
        }
    }
    T::true($created <= 40, "created $created");
});

T::test('tracking: order and payment flags feed the conversion funnel', function () {
    an_reset();
    $jar = tr_cookies(tr_hit(tr_req('/api/track/hit', [], ['first' => true, 'path' => '/'])));
    $ctx = Tracking::context(tr_req('/api/checkout', $jar));
    T::true($ctx['visitor_id'] !== null && $ctx['session_id'] !== null);
    Clock::advance(4);
    Tracking::qualify(tr_req('/api/track/qualify', $jar));
    Tracking::onOrderCreated($ctx['visitor_id'], $ctx['session_id']);
    Tracking::onPaymentSucceeded($ctx['visitor_id'], $ctx['session_id']);
    $d = Dashboard::build(['range' => 'today']);
    T::same(1, $d['totals']['visitors']);
    T::same(1, $d['totals']['order_visitors']);
    T::same(1, $d['totals']['paid_visitors']);
    T::same(1000, $d['visitors']['conv_order_tenths'], '100.0 %');
    T::same(1000, $d['visitors']['conv_paid_tenths']);
});

T::test('ads: CPC validation and per-order ad-cost estimate', function () {
    an_reset();
    $admin = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test'];
    foreach (['abc', '-1', '1.005', '1001'] as $bad) {
        T::throws(App\Core\HttpException::class, fn() => Ads::update($bad, 3, '4', Request::fake('POST', '/x'), $admin), 422);
    }
    T::throws(App\Core\HttpException::class, fn() => Ads::update('1.08', 0, '4', Request::fake('POST', '/x'), $admin), 422);
    T::throws(App\Core\HttpException::class, fn() => Ads::update('1.08', 3, '0', Request::fake('POST', '/x'), $admin), 422);
    $cur = Ads::update('1.08', 3, '4', Request::fake('POST', '/x'), $admin);
    T::same(108, $cur['cpc_paise']);
    $est = Ads::estimatedPerOrder();
    T::same(2700, $est['paise'], 'CPC ₹1.08 ÷ 4 % assumed conversion = ₹27 per order');
    T::contains('assumed 4.0% conversion', $est['basis']);
    // real data takes over once there are enough orders
    $today = Tz::today();
    for ($i = 0; $i < 10; $i++) {
        an_order('MANUAL', 500, 0);
    }
    an_visitors(100, 100, $today);
    $est2 = Ads::estimatedPerOrder();
    T::same(1080, $est2['paise'], '₹108 ads ÷ 10 paid orders');
    T::contains('last 30 days', $est2['basis']);
});
