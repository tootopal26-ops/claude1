<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Services\AdSpend;
use App\Services\AdsIntel;
use App\Services\Analytics;
use App\Services\Attribution;
use App\Services\Dashboard;
use App\Services\JobContext;
use App\Services\JobHandlers;
use App\Services\Orders;
use App\Services\Settings;
use App\Services\Settlement;
use App\Services\Tracking;
use App\Support\Clock;
use App\Support\Tz;

/**
 * Ads Intelligence: what the visitor tracker now records (campaign tags, page views, returning, engaged), how a verified order is credited to a campaign
 * (once, on the server, last non-direct touch inside a window), and that the campaign figures add up to the dashboard's — visitors, qualified visitors and
 * advertising cost — with actual spend replacing the estimate and missing data named instead of guessed.
 */

const AD_ADMIN = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];

function ad_ip(): string
{
    static $n = 0;
    $n++;
    return '203.0.' . intdiv($n, 250) . '.' . ($n % 250 + 1);
}

/** One page load of a browser holding $jar. @return array<string,string> the cookies the browser now holds */
function ad_visit(array $jar, array $body = [], ?string $ip = null): array
{
    $ip ??= ad_ip();
    $body += ['first' => $jar === [], 'path' => '/'];
    return tr_cookies(tr_hit(tr_req('/api/track/hit', $jar, $body, $ip)), $jar);
}

/** The "stayed 3 seconds" report. */
function ad_qualify(array $jar, string $ip, int $afterSeconds = 4): array
{
    Clock::advance($afterSeconds);
    return Tracking::qualify(tr_req('/api/track/qualify', $jar, [], $ip));
}

function ad_ping(array $jar, string $ip, array $body): void
{
    $req = tr_req('/api/track/ping', $jar, $body, $ip);
    Tracking::ping($req, $req->json());
}

/** Place and pay an order as the visitor $jar (the real checkout and settlement code). @return array order row */
function ad_buy(array $jar, string $ip, string $target, string $code = 'ig-followers-50'): array
{
    static $n = 0;
    $n++;
    $ctx = Tracking::context(tr_req('/api/checkout', $jar, [], $ip));
    $p = pf_place($code, $target, $ctx, ff_ip());
    Settlement::settle((int) $p['payment']['id'], ['source' => 'webhook', 'amount_paise' => (int) $p['payment']['amount_paise'], 'utr' => 'ADUTR' . $n . strtoupper(bin2hex(random_bytes(3)))]);
    return Orders::byId((int) $p['order']['id']);
}

/** A checkout nobody pays. @return array order row */
function ad_start(array $jar, string $ip, string $target, string $code = 'ig-followers-50'): array
{
    $ctx = Tracking::context(tr_req('/api/checkout', $jar, [], $ip));
    return pf_place($code, $target, $ctx, ff_ip())['order'];
}

function ad_world(): void
{
    pf_setup();
    Clock::setFixed(AN_DAY0);
}

/** @return array<string,array<string,mixed>> the campaign rows of a period by key */
function ad_rows(string $from, string $to): array
{
    return array_column(AdsIntel::campaigns($from, $to), null, 'key');
}

// ═════════════════════════════ what the tracker records ═════════════════════════════

T::test('ads tracking: campaign tags are cleaned and kept; a click on a different campaign starts a new session, the same tag or no tag does not', function () {
    an_reset();
    $ip = ad_ip();
    $jar = ad_visit([], ['utm_source' => 'Insta', 'utm_medium' => 'CPC', 'utm_campaign' => 'Summer Sale!!', 'utm_content' => '<script>alert(1)</script>', 'utm_term' => str_repeat('T', 200)], $ip);
    $s = Db::one('SELECT * FROM visitor_sessions');
    T::same(['insta', 'cpc', 'summer sale', 'scriptalert1script', str_repeat('t', 80)], [$s['utm_source'], $s['utm_medium'], $s['utm_campaign'], $s['utm_content'], $s['utm_term']], 'lower-case words only, 80 characters at most');
    T::same([1, 0], [(int) $s['page_views'], (int) $s['returning_visitor']]);
    // a reload of the tagged address and an inner page without a tag: the same session, two more page views
    $jar = ad_visit($jar, ['utm_campaign' => 'summer sale'], $ip);
    $jar = ad_visit($jar, ['path' => '/order/x'], $ip);
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM visitor_sessions'));
    T::same(3, (int) Db::val('SELECT page_views FROM visitor_sessions'), 'a refresh is a page view, never a new visitor or session');
    // a click on a DIFFERENT campaign: a new session (and a new session cookie); the earlier one is left as it was
    $before = $jar[Tracking::SESSION_COOKIE];
    $jar = ad_visit($jar, ['utm_campaign' => 'winter'], $ip);
    T::true($jar[Tracking::SESSION_COOKIE] !== $before);
    T::same(['summer sale', 'winter'], Db::col('SELECT utm_campaign FROM visitor_sessions ORDER BY id'));
    T::same([3, 1], array_map('intval', Db::col('SELECT page_views FROM visitor_sessions ORDER BY id')));
    T::same([0, 0], array_map('intval', Db::col('SELECT returning_visitor FROM visitor_sessions ORDER BY id')), 'the same visit is not a "returning" visitor');
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM visitors'));
    // idle for 31 minutes: a new visit of a visitor the shop already knows
    Clock::advance(31 * 60);
    ad_visit($jar, [], $ip);
    T::same(1, (int) Db::val('SELECT returning_visitor FROM visitor_sessions ORDER BY id DESC LIMIT 1'));
});

T::test('ads tracking: hostile campaign parameters are neutralised, never stored as markup, and an empty tag is "no campaign"', function () {
    an_reset();
    $hostile = ['"><img src=x onerror=alert(1)>', "'; DROP TABLE visitors;--", 'javascript:alert(1)', '../../etc/passwd', str_repeat('é', 90), "line\nbreak\ttab", '   ', '%3Cscript%3E'];
    foreach ($hostile as $h) {
        ad_visit([], ['utm_source' => $h, 'utm_campaign' => $h, 'utm_content' => $h, 'utm_term' => $h]);
    }
    T::same(count($hostile), (int) Db::val('SELECT COUNT(*) FROM visitor_sessions'));
    foreach (Db::all('SELECT utm_source, utm_campaign, utm_content, utm_term FROM visitor_sessions') as $r) {
        foreach ($r as $k => $v) {
            T::true($v === null || (preg_match('/^[a-z0-9_.\- ]{1,80}$/D', $v) === 1), "$k is clean: " . json_encode($v));
        }
    }
    T::same(2, (int) Db::val('SELECT COUNT(*) FROM visitor_sessions WHERE utm_campaign IS NULL'), 'whitespace and non-ASCII-only tags are "no campaign"');
    T::same(count($hostile), (int) Db::val('SELECT COUNT(*) FROM visitors'), 'the visitors table is intact');
});

T::test('ads tracking: the visitor-day takes the first campaign-tagged session of the day; the 3-second rule and the single daily charge are unchanged', function () {
    an_reset();
    $ip = ad_ip();
    $jar = ad_visit([], [], $ip);                                   // came directly
    T::same(null, Db::val('SELECT utm_campaign FROM visitor_days'));
    Clock::advance(60);
    $jar = ad_visit($jar, ['utm_campaign' => 'alpha'], $ip);
    T::same('alpha', Db::val('SELECT utm_campaign FROM visitor_days'), 'the first tagged session names the day');
    $jar = ad_visit($jar, ['utm_campaign' => 'beta'], $ip);
    T::same('alpha', Db::val('SELECT utm_campaign FROM visitor_days'), 'nothing later changes it');
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM visitor_days'));
    T::false(Tracking::qualify(tr_req('/api/track/qualify', $jar, [], $ip))['qualified'], 'not before the qualifying time');
    $q = ad_qualify($jar, $ip);
    T::same([true, true], [$q['qualified'], $q['charged']]);
    T::same(false, ad_qualify($jar, $ip, 5)['charged'] ?? false, 'again: no second charge');
    T::same(108, (int) Db::val('SELECT SUM(cpc_paise) FROM visitor_days'));
    $today = Tz::today();
    $rows = ad_rows($today, $today);
    T::same([1, 108], [$rows['alpha']['qualified_visitors'], $rows['alpha']['spend_estimated']], 'the estimated spend belongs to the campaign that named the day');
    T::same([0, 0, 1], [$rows['beta']['qualified_visitors'], $rows['beta']['visitors'], $rows['beta']['sessions']], 'a campaign with a session but no visitor-day of its own');
    T::same([1, 1, 1], [$rows[AdsIntel::NONE]['sessions'], $rows['alpha']['sessions'], $rows['beta']['sessions']]);
});

T::test('ads tracking: engaged = qualified AND opened a service — whichever comes second sets it, once; the count is bounded and never decides spend', function () {
    an_reset();
    // a card first, the qualifying time later
    $ip = ad_ip();
    $jar = ad_visit([], [], $ip);
    ad_ping($jar, $ip, ['active_s' => 1, 'services' => 1]);
    T::same([1, null], [(int) Db::val('SELECT service_views FROM visitor_sessions'), Db::val('SELECT engaged_at FROM visitor_sessions')], 'not qualified yet: not engaged');
    ad_qualify($jar, $ip);
    $at = Db::val('SELECT engaged_at FROM visitor_sessions');
    T::true($at !== null, 'qualified after a card: engaged');
    Clock::advance(30);
    ad_ping($jar, $ip, ['services' => 3]);
    T::same([3, $at], [(int) Db::val('SELECT service_views FROM visitor_sessions'), Db::val('SELECT engaged_at FROM visitor_sessions')], 'the first moment is kept');
    // qualified first, a card later
    $ip2 = ad_ip();
    $jar2 = ad_visit([], [], $ip2);
    ad_qualify($jar2, $ip2);
    T::same(null, Db::val('SELECT engaged_at FROM visitor_sessions WHERE id = 2'), 'qualified, no card yet');
    ad_ping($jar2, $ip2, ['services' => 99999]);
    T::same(200, (int) Db::val('SELECT service_views FROM visitor_sessions WHERE id = 2'), 'bounded');
    T::true(Db::val('SELECT engaged_at FROM visitor_sessions WHERE id = 2') !== null);
    foreach ([-5, 'abc', null, [1]] as $junk) {
        ad_ping($jar2, $ip2, ['services' => $junk]);
    }
    T::same(200, (int) Db::val('SELECT service_views FROM visitor_sessions WHERE id = 2'), 'a smaller or invalid report never lowers it');
    // qualified, never opened a card: counted (and billed) as qualified, not engaged
    $ip3 = ad_ip();
    $jar3 = ad_visit([], [], $ip3);
    $q = ad_qualify($jar3, $ip3);
    T::same([true, true], [$q['qualified'], $q['charged']]);
    T::same(null, Db::val('SELECT engaged_at FROM visitor_sessions WHERE id = 3'));
    T::same(3, (int) Db::val('SELECT COUNT(*) FROM visitor_days WHERE qualified = 1'));
    T::same(3 * 108, (int) Db::val('SELECT SUM(cpc_paise) FROM visitor_days'), 'engagement changes nothing that is billed');
    $r = ad_rows(Tz::today(), Tz::today())[AdsIntel::NONE];
    T::same([3, 3, 2], [$r['sessions'], $r['qualified_sessions'], $r['engaged']]);
});

// ═════════════════════════════ which campaign gets the credit ═════════════════════════════

T::test('ads attribution: the last non-direct touch inside the window gets the credit — written once, at verification, from server-side data', function () {
    ad_world();
    $ip = ad_ip();
    Clock::setFixed(AN_DAY0 - 3 * 86400);                           // three days ago: a click on a campaign
    $jar = ad_visit([], ['utm_source' => 'meta', 'utm_medium' => 'cpc', 'utm_campaign' => 'diwali', 'utm_content' => 'reel-a', 'utm_term' => 'followers'], $ip);
    $touch = Db::val('SELECT started_at FROM visitor_sessions');
    Clock::setFixed(AN_DAY0 - 86400);                               // yesterday: a direct visit
    $jar = ad_visit($jar, [], $ip);
    Clock::setFixed(AN_DAY0);                                       // today: back directly, and buys
    $jar = ad_visit($jar, [], $ip);
    $o = ad_buy($jar, $ip, 'https://www.instagram.com/buyer1/');
    $a = Db::one('SELECT * FROM campaign_attribution WHERE order_id = ?', [$o['id']]);
    T::same(['last_non_direct', 7, 'campaign', 'meta', 'cpc', 'diwali', 'reel-a', 'followers', $touch], [$a['model'], (int) $a['window_days'], $a['touch_kind'], $a['utm_source'], $a['utm_medium'], $a['utm_campaign'], $a['utm_content'], $a['utm_term'], $a['touch_at']],
        'the direct visits in between do not take the credit away from the campaign click');
    // written once: a second call, a changed window and a later campaign click change nothing
    T::false(Attribution::record((int) $o['id']));
    Attribution::saveSettings(['window_days' => 1], null, AD_ADMIN);
    Clock::advance(60);
    ad_visit($jar, ['utm_campaign' => 'someone-else'], $ip);
    T::same($a, Db::one('SELECT * FROM campaign_attribution WHERE order_id = ?', [$o['id']]), 'nothing the browser sends afterwards, and no setting, rewrites it');
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM campaign_attribution WHERE order_id = ?', [$o['id']]));
    // the window is respected: with one day the three-day-old click cannot be reached
    Clock::setFixed(AN_DAY0 + 3600);
    $ip2 = ad_ip();
    Clock::setFixed(AN_DAY0 + 3600 - 3 * 86400);
    $jar2 = ad_visit([], ['utm_campaign' => 'diwali'], $ip2);
    Clock::setFixed(AN_DAY0 + 3600);
    $jar2 = ad_visit($jar2, [], $ip2);
    $o2 = ad_buy($jar2, $ip2, 'https://www.instagram.com/buyer2/');
    $b = Db::one('SELECT * FROM campaign_attribution WHERE order_id = ?', [$o2['id']]);
    T::same(['direct', null, 1], [$b['touch_kind'], $b['utm_campaign'], (int) $b['window_days']]);
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM visitor_sessions WHERE id = ?', [$b['visitor_session_id']]), 'a direct order still names the session it came from');
});

T::test('ads attribution: a source without a campaign tag is credited as the source; an order nobody could track is direct; the window setting is validated and audited', function () {
    ad_world();
    // an Instagram referral, no UTM tags
    $ip = ad_ip();
    $jar = ad_visit([], ['ref' => 'https://l.instagram.com/?u=x'], $ip);
    $o = ad_buy($jar, $ip, 'https://www.instagram.com/buyer3/');
    $a = Db::one('SELECT * FROM campaign_attribution WHERE order_id = ?', [$o['id']]);
    T::same(['source', 'instagram', null], [$a['touch_kind'], $a['source'], $a['utm_campaign']]);
    // cookies blocked: no visitor, no session
    $p = pf_place('ig-followers-50', 'https://www.instagram.com/buyer4/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    Settlement::settle((int) $p['payment']['id'], ['source' => 'webhook', 'amount_paise' => (int) $p['payment']['amount_paise'], 'utr' => 'ADUTRX' . strtoupper(bin2hex(random_bytes(3)))]);
    $b = Db::one('SELECT * FROM campaign_attribution WHERE order_id = ?', [(int) $p['order']['id']]);
    T::same(['direct', null, null], [$b['touch_kind'], $b['utm_campaign'], $b['visitor_session_id']]);
    // an order that is not paid has none
    $unpaid = pf_place('ig-followers-50', 'https://www.instagram.com/buyer5/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    T::false(Attribution::record((int) $unpaid['order']['id']), 'only a verified payment is attributed');
    // the window
    T::same(7, Attribution::windowDays());
    foreach (['0', '91', 'x', '7.5', '', -1, 0, 1000] as $bad) {
        T::throws(HttpException::class, fn() => Attribution::saveSettings(['window_days' => $bad], null, AD_ADMIN), 422);
    }
    T::same(7, Attribution::windowDays(), 'a refused change changes nothing');
    T::same(['window_days' => 14, 'model' => 'last_non_direct'], Attribution::saveSettings(['window_days' => '14'], null, AD_ADMIN));
    T::same(14, Attribution::windowDays());
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.attribution_changed'"));
    Attribution::saveSettings(['window_days' => 14], null, AD_ADMIN);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.attribution_changed'"), 'saving the same value is not a change');
});

T::test('ads attribution: a verified order whose row was missed is attributed later from the same server-side data (dry run first, idempotent, by the analytics job)', function () {
    ad_world();
    $ip = ad_ip();
    $jar = ad_visit([], ['utm_campaign' => 'later'], $ip);
    $o = ad_buy($jar, $ip, 'https://www.instagram.com/buyer6/');
    Db::exec('DELETE FROM campaign_attribution WHERE order_id = ?', [$o['id']]);       // the process died between the commit and the attribution
    T::same(1, Attribution::missing());
    T::same(1, Attribution::backfill(200, 14, true), 'a dry run counts');
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM campaign_attribution'), 'and writes nothing');
    $run = JobHandlers::analytics(new JobContext(microtime(true) + 30, true));
    T::false(isset($run['attribution_filled']), 'the job\'s own dry run writes nothing');
    $run = JobHandlers::analytics(new JobContext(microtime(true) + 30, false));
    T::same(1, $run['attribution_filled']);
    T::same('later', Db::val('SELECT utm_campaign FROM campaign_attribution WHERE order_id = ?', [$o['id']]));
    T::same([0, 0], [Attribution::missing(), Attribution::backfill()]);
    // an order older than the horizon is only reached when asked for
    Db::exec('DELETE FROM campaign_attribution WHERE order_id = ?', [$o['id']]);
    Db::exec('UPDATE orders SET paid_at = ? WHERE id = ?', [Clock::sql(Clock::now() - 20 * 86400), $o['id']]);
    T::same([0, 1], [Attribution::missing(14), Attribution::missing(30)]);
    T::same([0, 1], [Attribution::backfill(200, 14, true), Attribution::backfill(200, 30, true)]);
    T::same(1, Attribution::backfill(200, 30));
    T::same('direct', Db::val('SELECT touch_kind FROM campaign_attribution WHERE order_id = ?', [$o['id']]), 'no session of that visitor in the window before that payment: direct, and said so');
});

// ═════════════════════════════ the numbers ═════════════════════════════

/** A small world: 6 visitors of today across two campaigns and direct, two verified orders, one unpaid checkout. @return array<string,mixed> */
function ad_scenario(): array
{
    ad_world();
    $w = [];
    foreach ([['a', 'alpha', true, 'pay'], ['b', 'alpha', true, null], ['c', 'beta', true, 'start'], ['d', null, true, 'pay'], ['e', 'beta', false, null], ['f', null, false, null]] as [$k, $campaign, $stays, $buys]) {
        $ip = ad_ip();
        $jar = ad_visit([], $campaign !== null ? ['utm_source' => 'meta', 'utm_campaign' => $campaign] : [], $ip);
        if ($stays) {
            ad_qualify($jar, $ip);
        }
        if ($buys === 'pay') {
            $w[$k] = ad_buy($jar, $ip, "https://www.instagram.com/{$k}buyer/");
        } elseif ($buys === 'start') {
            $w[$k] = ad_start($jar, $ip, "https://www.instagram.com/{$k}buyer/");
        }
    }
    return $w;
}

T::test('ads overview: the campaign rows add up to the dashboard — visitors, qualified visitors, checkout starts and the advertising cost — and the units are stated', function () {
    $w = ad_scenario();
    $today = Tz::today();
    $o = AdsIntel::overview(['range' => 'today']);
    $d = Dashboard::build(['range' => 'today'])['totals'];
    $c = $o['cards'];
    T::same([6, 4, 3], [$d['visitors'], $d['qualified_visitors'], $d['order_visitors']], 'the dashboard counts what the scenario made');
    T::same([$d['visitors'], $d['qualified_visitors'], $d['order_visitors']], [$c['visitors'], $c['qualified'], $c['checkout_starts']], 'the Ads cards are the dashboard\'s numbers');
    T::same(['visitors' => 6, 'qualified_visitors' => 4, 'checkout_starts' => 3], [
        'visitors' => array_sum(array_column($o['campaigns'], 'visitors')),
        'qualified_visitors' => array_sum(array_column($o['campaigns'], 'qualified_visitors')),
        'checkout_starts' => array_sum(array_column($o['campaigns'], 'checkout_starts')),
    ], 'the rows add up to the cards');
    T::same(4 * 108, $d['ads_spend_paise']);
    T::same(4 * 108, array_sum(array_column($o['campaigns'], 'spend_used')), 'the rows add up to the advertising cost — to the paisa');
    T::same(4 * 108, $c['advertising_used']);
    T::same(['estimated', 4 * 108, 0], [$c['spend_today']['source'], $c['spend_today']['paise'], $c['spend_today']['actual_paise']]);
    $rows = ad_rows($today, $today);
    T::same([2, 2, 216, 'estimated'], [$rows['alpha']['visitors'], $rows['alpha']['qualified_visitors'], $rows['alpha']['spend_used'], $rows['alpha']['spend_source']]);
    T::same([2, 1, 108], [$rows['beta']['visitors'], $rows['beta']['qualified_visitors'], $rows['beta']['spend_used']], 'a visitor who left before 3 seconds is a visitor, not a cost');
    T::same([2, 1, 108], [$rows[AdsIntel::NONE]['visitors'], $rows[AdsIntel::NONE]['qualified_visitors'], $rows[AdsIntel::NONE]['spend_used']]);
    // the order side: verified payments only, credited by the model
    T::same([2, 2], [$c['orders'], $c['customers']], 'a checkout nobody paid is not an order of this table');
    T::same([1, 1, 0], [$rows['alpha']['orders'], $rows[AdsIntel::NONE]['orders'], $rows['beta']['orders'] ?? 0]);
    T::same([500, 1000], [$rows['alpha']['conversion_tenths'], $rows[AdsIntel::NONE]['conversion_tenths']], '1 customer ÷ 2 qualified visitors = 50.0 %; 1 ÷ 1 = 100.0 %');
    T::same(0, $rows['beta']['conversion_tenths'], 'qualified visitors, no customers');
    T::same(['alpha' => 216, AdsIntel::NONE => 108], ['alpha' => $rows['alpha']['cost_per_customer'], AdsIntel::NONE => $rows[AdsIntel::NONE]['cost_per_customer']], 'cost per customer = advertising cost ÷ verified customers');
    T::same(null, $rows['beta']['cost_per_customer'], 'no customer, no cost per customer');
    // the units are said on the screen
    foreach (['visitors', 'qualified', 'sessions', 'engaged', 'checkout_starts', 'customers', 'attributed', 'conversion', 'cost_per_customer'] as $k) {
        T::true(isset($o['definitions'][$k]) && strlen($o['definitions'][$k]) > 20, "definition of $k");
    }
    T::contains('not incremental revenue', $o['definitions']['attributed']);
    T::contains('not incremental revenue', implode(' ', $o['notes']));
    T::same(['last_non_direct', 7], [$o['meta']['attribution']['model'], $o['meta']['attribution']['window_days']]);
});

T::test('ads overview: actual spend replaces the CPC estimate for its day — never both — splits by campaign, and follows the GST setting', function () {
    ad_scenario();
    $today = Tz::today();
    $before = array_sum(array_column(AdsIntel::overview(['range' => 'today'])['campaigns'], 'spend_used'));
    T::same(432, $before);
    // actual spend for ONE campaign of today
    AdSpend::save(['day' => $today, 'campaign' => 'alpha', 'platform' => 'meta', 'amount' => '10', 'gst' => '1.80', 'clicks' => '20', 'impressions' => '900'], fn_req(), AD_ADMIN);
    $o = AdsIntel::overview(['range' => 'today']);
    $dash = Dashboard::build(['range' => 'today'])['totals'];
    T::same(1180, $dash['ads_spend_paise'], 'the day is now actual: ₹10 + ₹1.80 GST (a cost by default) — and no estimate on top');
    T::same(1180, array_sum(array_column($o['campaigns'], 'spend_used')), 'the rows still add up to it');
    $rows = array_column($o['campaigns'], null, 'key');
    T::same([1180, 'actual', 0, 'none'], [$rows['alpha']['spend_used'], $rows['alpha']['spend_source'], $rows['beta']['spend_used'], $rows['beta']['spend_source']], 'a day with actual spend carries no estimate for the other campaigns');
    T::same(['actual', 1180, 0, 1], [$o['cards']['spend_today']['source'], $o['cards']['spend_today']['paise'], $o['cards']['spend_today']['estimated_paise'], $o['cards']['spend_today']['actual_days']]);
    T::same(50, $rows['alpha']['cpc'], 'CPC = ₹10 pre-tax ÷ 20 clicks the platform reported');
    T::same(null, $rows['beta']['cpc'], 'no clicks, no CPC');
    T::false(str_contains(implode(' ', $o['notes']), 'All advertising cost in this period is the CPC estimate'), 'the screen no longer says everything is an estimate');
    // GST recoverable: not counted
    App\Services\Finance::saveSettings(['ad_gst' => 'recoverable'], fn_req(), AD_ADMIN);
    Analytics::resetMemo();
    T::same(1000, array_sum(array_column(AdsIntel::overview(['range' => 'today'])['campaigns'], 'spend_used')));
    T::same(1000, Dashboard::build(['range' => 'today'])['totals']['ads_spend_paise']);
    // a voided entry brings the estimate back
    $id = (int) Db::val('SELECT id FROM ad_daily_spend');
    AdSpend::void($id, 'entered by mistake', fn_req(), AD_ADMIN);
    Analytics::resetMemo();
    T::same(432, array_sum(array_column(AdsIntel::overview(['range' => 'today'])['campaigns'], 'spend_used')));
    // spend entered without a campaign is real, but has nobody to be credited to
    AdSpend::save(['day' => $today, 'campaign' => '', 'platform' => 'google', 'amount' => '5'], fn_req(), AD_ADMIN);
    $r = array_column(AdsIntel::overview(['range' => 'today'])['campaigns'], null, 'key');
    T::same([500, 0], [$r[AdsIntel::DAY_TOTAL]['spend_used'], $r[AdsIntel::DAY_TOTAL]['visitors']]);
    T::same(500, array_sum(array_column($r, 'spend_used')));
    // an actual ₹0 is a statement ("nothing was spent"), not a missing entry
    $y = Tz::addDays($today, -1);
    AdSpend::save(['day' => $y, 'campaign' => '', 'platform' => '', 'amount' => '0'], fn_req(), AD_ADMIN);
    $block = AdsIntel::overview(['range' => 'custom', 'from' => $y, 'to' => $y])['cards']['spend_period'];
    T::same(['actual', 0, 1], [$block['source'], $block['paise'], $block['actual_days']]);
});

T::test('ads overview: attributed revenue is refund-adjusted, its costs include every order cost, and with every order attributed it equals the shop\'s contribution minus advertising', function () {
    $w = ad_scenario();
    $today = Tz::today();
    Db::exec('UPDATE orders SET refunded_paise = 200 WHERE id = ?', [$w['a']['id']]);
    Db::exec("UPDATE orders SET mode = 'AUTO', provider_cost_paise = 120, provider_cost_final = 1 WHERE id = ?", [$w['d']['id']]);
    Db::exec("INSERT INTO order_costs (order_id, cost_type, amount_paise, source, is_estimate, note, effective_at, created_at, active_key) VALUES (?, 'payment_fee', 15, 'rate', 1, 't', ?, ?, ?)",
        [$w['a']['id'], Clock::sql(), Clock::sql(), $w['a']['id'] . ':payment_fee']);
    Analytics::markDirty($today);
    $o = AdsIntel::overview(['range' => 'today']);
    $rows = array_column($o['campaigns'], null, 'key');
    T::same([300, 200, 15, 285, 285 - 216], [$rows['alpha']['attributed_revenue'], $rows['alpha']['attributed_refunds'], $rows['alpha']['attributed_costs'], $rows['alpha']['attributed_contribution'], $rows['alpha']['contribution_after_ads']],
        '₹5 paid − ₹2 refunded = ₹3 of revenue; ₹0.15 fee; ₹2.16 of advertising');
    T::same([500, 120, 380], [$rows[AdsIntel::NONE]['attributed_revenue'], $rows[AdsIntel::NONE]['attributed_costs'], $rows[AdsIntel::NONE]['attributed_contribution']], 'the provider cost of an AUTO order');
    $site = Analytics::sum(Analytics::rollup($today, $today));
    T::same($site['gross_profit_paise'] - $site['ads_spend_paise'], $o['cards']['contribution_after_ads'], 'Ads Intelligence and Finance agree: contribution − advertising');
    T::same($site['ads_spend_paise'], $o['cards']['advertising_used']);
    // one campaign by itself
    $one = AdsIntel::overview(['range' => 'today', 'campaign' => 'alpha']);
    T::same(['alpha'], array_column($one['campaigns'], 'key'));
    T::same([300, 2, 216, 1], [$one['cards']['attributed_revenue'], $one['cards']['qualified'], $one['cards']['advertising_used'], $one['cards']['customers']]);
    T::same(285 - 216, $one['cards']['contribution_after_ads']);
    T::same(216, $one['cards']['spend_today']['paise'], 'today\'s spend card follows the filter');
    T::same([AdsIntel::NONE], array_column(AdsIntel::overview(['range' => 'today', 'campaign' => AdsIntel::NONE])['campaigns'], 'key'));
    T::throws(HttpException::class, fn() => AdsIntel::overview(['range' => 'today', 'campaign' => 'nothing-like-it']), 422);
    // the trend adds up to the same orders and revenue
    $t = AdsIntel::overview(['range' => '7d']);
    T::same([2, 800, 432], [array_sum(array_column($t['trend'], 'orders')), array_sum(array_column($t['trend'], 'revenue')), array_sum(array_column($t['trend'], 'spend'))], 'the trend adds up to the same orders, revenue and advertising');
    $ta = AdsIntel::overview(['range' => '7d', 'campaign' => 'alpha'])['trend'];
    T::same([1, 300, 216], [array_sum(array_column($ta, 'orders')), array_sum(array_column($ta, 'revenue')), array_sum(array_column($ta, 'spend'))], 'and for one campaign');
});

T::test('ads overview: missing data is named, not guessed — unattributed orders, untracked orders, sessions before page views were measured, no clicks, no customers', function () {
    $w = ad_scenario();
    $today = Tz::today();
    $c = AdsIntel::overview(['range' => 'today']);
    T::same(['unattributed_orders' => 0, 'unattributed_revenue' => 0, 'untracked_orders' => 0, 'unmeasured_sessions' => 0], $c['coverage']);
    // an order paid before attribution existed
    Db::exec('DELETE FROM campaign_attribution WHERE order_id = ?', [$w['a']['id']]);
    // an order from a visitor the shop could not track
    $p = pf_place('ig-followers-50', 'https://www.instagram.com/untracked/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    Settlement::settle((int) $p['payment']['id'], ['source' => 'webhook', 'amount_paise' => (int) $p['payment']['amount_paise'], 'utr' => 'ADUTRU' . strtoupper(bin2hex(random_bytes(3)))]);
    // a session recorded before page views were measured
    Db::exec('UPDATE visitor_sessions SET page_views = 0 WHERE id = 1');
    $o = AdsIntel::overview(['range' => 'today']);
    T::same(['unattributed_orders' => 1, 'unattributed_revenue' => 500, 'untracked_orders' => 1, 'unmeasured_sessions' => 1], $o['coverage']);
    $notes = implode("\n", $o['notes']);
    T::contains('not credited to any campaign', $notes);
    T::contains('could not track', $notes);
    T::contains('before page views were measured', $notes);
    T::contains('CPC estimate', $notes);
    $rows = array_column($o['campaigns'], null, 'key');
    T::same([2, 2], [$o['cards']['orders'], $o['cards']['customers']], 'the unattributed order is in no row; the untracked one counts as one customer, under "no campaign"');
    T::same(2, $rows[AdsIntel::NONE]['orders']);
    T::same(null, $o['cards']['cpc'], 'no clicks were entered: there is no CPC to show');
    T::same('low', $o['cards']['confidence']['level']);
    T::contains('estimate', $o['cards']['confidence']['why']);
    // a period with no visitors and no spend: no ratio is invented
    $y = Tz::addDays($today, -3);
    $e = AdsIntel::overview(['range' => 'custom', 'from' => $y, 'to' => $y]);
    T::same([null, null, null, null, 0, 'none'], [$e['cards']['conversion_tenths'], $e['cards']['cost_per_customer'], $e['cards']['cost_per_qualified'], $e['cards']['cpc'], $e['cards']['qualified'], $e['cards']['spend_period']['source']]);
    T::same([], $e['campaigns']);
});

T::test('ads overview: confidence is earned by actual spend and a sample of attributed orders, and says why', function () {
    ad_world();
    $today = Tz::today();
    $make = function (int $orders) use ($today): void {
        Db::exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['campaign_attribution', 'payments', 'order_status_history', 'orders'] as $t) {
            Db::exec('DELETE FROM `' . $t . '`');
        }
        Db::exec('SET FOREIGN_KEY_CHECKS = 1');
        for ($i = 0; $i < $orders; $i++) {
            $id = an_order('MANUAL', 500, 0, $today);
            Db::exec("INSERT INTO campaign_attribution (order_id, model, window_days, touch_kind, utm_campaign, source, attributed_at) VALUES (?, 'last_non_direct', 7, 'campaign', 'big', 'meta', ?)", [$id, Clock::sql()]);
        }
    };
    $make(31);
    an_visitors(5, 5, $today);
    $level = static fn(): array => array_column(AdsIntel::campaigns($today, $today), null, 'key')['big']['confidence'];
    T::same('low', $level()['level'], 'a CPC estimate is not billed spend');
    AdSpend::save(['day' => $today, 'campaign' => 'big', 'platform' => 'meta', 'amount' => '50'], fn_req(), AD_ADMIN);
    T::same('high', $level()['level']);
    T::contains('31 attributed orders', $level()['why']);
    $make(12);
    T::same('medium', $level()['level']);
    $make(3);
    T::same('low', $level()['level']);
    T::contains('only 3 attributed orders', $level()['why']);
});

// ═════════════════════════════ older data ═════════════════════════════

T::test('migration 012: an existing visitor-day is named after the first tagged session of its day, best effort; the rest stay "no campaign"', function () {
    an_reset();
    $now = Clock::sql();
    $day = Tz::today();
    $mk = static function (string $anon) use ($now): int {
        return Db::insert('visitors', ['anon_id' => $anon, 'first_seen_at' => $now, 'last_seen_at' => $now]);
    };
    $v1 = $mk(str_repeat('a', 32));
    $v2 = $mk(str_repeat('b', 32));
    $s = static function (int $v, string $key, int $offset, ?string $campaign) use ($now, $day): void {
        Db::insert('visitor_sessions', ['visitor_id' => $v, 'session_key' => $key, 'day' => $day, 'started_at' => Clock::sql(Clock::now() + $offset), 'last_hit_at' => $now, 'last_seen_at' => $now, 'utm_campaign' => $campaign]);
    };
    $s($v1, str_repeat('1', 32), 0, null);
    $s($v1, str_repeat('2', 32), 60, 'first-tag');
    $s($v1, str_repeat('3', 32), 120, 'second-tag');
    $s($v2, str_repeat('4', 32), 0, null);
    foreach ([$v1, $v2] as $v) {
        Db::insert('visitor_days', ['visitor_id' => $v, 'day' => $day, 'created_at' => $now]);
    }
    $sql = (string) file_get_contents(SMB_ROOT . '/database/migrations/012_visitor_day_campaign.sql');
    $update = '';
    foreach (explode(';', $sql) as $stmt) {
        if (str_contains($stmt, 'UPDATE `visitor_days`')) {
            $update = $stmt;
        }
    }
    T::true($update !== '', 'the migration has the backfill statement');
    Db::exec($update);
    T::same('first-tag', Db::val('SELECT utm_campaign FROM visitor_days WHERE visitor_id = ?', [$v1]));
    T::same(null, Db::val('SELECT utm_campaign FROM visitor_days WHERE visitor_id = ?', [$v2]));
    Db::exec($update);
    T::same('first-tag', Db::val('SELECT utm_campaign FROM visitor_days WHERE visitor_id = ?', [$v1]), 'running it again changes nothing');
});
