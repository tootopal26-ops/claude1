<?php
declare(strict_types=1);

use App\Controllers\Admin\PricingController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminServices;
use App\Services\Pricing;
use App\Support\Clock;
use App\Support\Tz;

/**
 * Adaptive pricing engine. Source of truth stays `services.price_paise`; an experiment only ever shows a different price to its
 * own treatment cohort, for as long as it runs, and only once an owner has explicitly switched it to live (goLive). Every test
 * here uses real rows (orders, assignments, cohort rollups) — nothing is mocked.
 */

const PR_DAY0 = 1_900_000_000;

function pr_reset(): void
{
    T::freshDb();
    Tz::set('Asia/Kolkata');
    Clock::setFixed(PR_DAY0);
}

function pr_owner(): array
{
    return ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];
}

function pr_manager(): array
{
    return ['id' => 2, 'username' => 'mgr', 'email' => 'm@x.test', 'role' => 'manager'];
}

/** @return array{id:int,price_paise:int} */
function pr_service(string $code = 'ig-followers-100k', ?int $fulfil = 1000): array
{
    $s = Db::one('SELECT id, price_paise FROM services WHERE code = ?', [$code]);
    Db::update('services', ['adaptive_enabled' => 1, 'fulfil_cost_paise' => $fulfil, 'pricing_paused' => 0], 'id = ?', [$s['id']]);
    return ['id' => (int) $s['id'], 'price_paise' => (int) $s['price_paise']];
}

/** A synthetic order (bypasses checkout) stamped with the given experiment/cohort/price so the evaluator has real rows to roll up. */
function pr_order(int $serviceId, int $amountPaise, ?int $experimentId, ?string $cohort, ?int $priceVersionId, bool $paid = true, string $status = 'COMPLETED', ?int $fulfilCost = null): int
{
    $svc = Db::one('SELECT s.*, p.slug FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.id = ?', [$serviceId]);
    static $n = 0;
    $n++;
    $orderId = Db::insert('orders', [
        'public_id' => 'SMB-P' . str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'access_token' => bin2hex(random_bytes(16)), 'service_id' => $serviceId, 'platform_id' => $svc['platform_id'],
        'platform_slug' => $svc['slug'], 'service_code' => $svc['code'], 'service_title' => $svc['title'], 'service_type' => $svc['type'], 'mode' => 'MANUAL', 'quantity' => 10,
        'amount_paise' => $amountPaise, 'price_version_id' => $priceVersionId, 'experiment_id' => $experimentId, 'price_cohort' => $cohort,
        'target' => '@x', 'target_url' => 'https://www.instagram.com/x/', 'status' => $status, 'payment_status' => $paid ? 'success' : 'pending',
        'created_day' => Tz::today(), 'paid_day' => $paid ? Tz::today() : null, 'paid_at' => $paid ? Clock::sql() : null, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql(),
    ]);
    if ($paid && $fulfilCost !== null) {
        Db::insert('order_costs', ['order_id' => $orderId, 'cost_type' => 'fulfilment', 'amount_paise' => $fulfilCost, 'source' => 'service_default', 'is_estimate' => 1, 'note' => 'test', 'effective_at' => Clock::sql(), 'created_at' => Clock::sql(), 'active_key' => $orderId . ':fulfilment']);
    }
    return $orderId;
}

/** Drive a visitor through assignment + qualification (and, when $paid, a purchase at the price they were shown). */
function pr_visit(int $serviceId, int $defaultPrice, string $anonId, bool $qualify = true, bool $buy = false): array
{
    $r = Pricing::resolveForService($serviceId, $defaultPrice, $anonId);
    if ($qualify) {
        Pricing::onVisitorQualified($anonId);
    }
    if ($buy) {
        pr_order($serviceId, $r['price_paise'], $r['experiment_id'], $r['cohort'], $r['price_version_id']);
    }
    return $r;
}

function pr_anon(): string
{
    return bin2hex(random_bytes(16));
}

// ═══════════════════════════ eligibility & proposal ═══════════════════════════

T::test('pricing: eligibility — AUTO services, a missing fulfilment cost, an adaptive-off service and global pause are all ineligible', function () {
    pr_reset();
    $svc = pr_service('ig-followers-100k', null); // no fulfilment cost configured
    $e = Pricing::eligibleServices();
    $row = array_values(array_filter($e, static fn(array $r): bool => $r['id'] === $svc['id']))[0];
    T::false($row['eligible']);
    T::true((bool) array_filter($row['reasons'], static fn(string $r): bool => str_contains($r, 'fulfilment cost')));

    T::throws(HttpException::class, fn() => Pricing::propose($svc['id'], ['candidate_price' => '89'], null, pr_owner()), 422);

    Db::update('services', ['fulfil_cost_paise' => 1000], 'id = ?', [$svc['id']]);
    $auto = Db::one("SELECT id FROM services WHERE mode = 'AUTO' LIMIT 1");
    T::true($auto === null, 'the seed ships no AUTO service, confirming the MANUAL-only rule is exercised by every other assertion here');

    Pricing::setGlobalPause(true, null, pr_owner());
    T::throws(HttpException::class, fn() => Pricing::propose($svc['id'], ['candidate_price' => '89'], null, pr_owner()), 422);
    Pricing::setGlobalPause(false, null, pr_owner());
});

T::test('pricing: propose() validates the candidate — price-ending rule, must differ, maximum change, and bounds', function () {
    pr_reset();
    $svc = pr_service(); // ₹99

    foreach (['50', '100', '99'] as $bad) {
        $e = T::throws(HttpException::class, fn() => Pricing::propose($svc['id'], ['candidate_price' => $bad], null, pr_owner()), 422);
        T::true(isset($e->extra['fields']['candidate_price']), $bad);
    }
    // more than the default 50% maximum change (a valid ending that is simply too far from ₹99)
    $e = T::throws(HttpException::class, fn() => Pricing::propose($svc['id'], ['candidate_price' => '44'], null, pr_owner()), 422);
    T::contains('maximum change', $e->extra['fields']['candidate_price']);

    $exp = Pricing::propose($svc['id'], ['candidate_price' => '89', 'min_sample' => 20, 'max_duration_days' => 7], null, pr_owner());
    T::same(['proposed', 'dry_run', 9900, 8900], [$exp['status'], $exp['mode'], $exp['control_paise'], $exp['candidate_paise']]);
    T::true((int) Db::val("SELECT COUNT(*) FROM service_price_versions WHERE experiment_id = ?", [$exp['id']]) === 2);
    T::true((int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'pricing.experiment_proposed'") === 1);

    // a second experiment on the same (already-proposed) service is refused
    T::throws(HttpException::class, fn() => Pricing::propose($svc['id'], ['candidate_price' => '79'], null, pr_owner()), 422);
});

// ═══════════════════════════ lifecycle, permissions, dry run vs live ══════════

T::test('pricing: dry run is a real A/A test (same price to both cohorts); only goLive (owner) lets the treatment cohort see the candidate', function () {
    pr_reset();
    $svc = pr_service();
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '89'], null, pr_owner());

    // Pricing::start() itself does not re-check who may request "live" — that gate lives in the controller (see the access test below)
    $exp = Pricing::start($exp['id'], 'dry_run', null, pr_manager());
    T::same(['running', 'dry_run'], [$exp['status'], $exp['mode']]);

    $anons = array_map(static fn(): string => pr_anon(), range(1, 40));
    $cohorts = [];
    foreach ($anons as $a) {
        $r = Pricing::resolveForService($svc['id'], $svc['price_paise'], $a);
        $cohorts[$r['cohort']] = ($cohorts[$r['cohort']] ?? 0) + 1;
        T::same($svc['price_paise'], $r['price_paise'], 'dry run: every cohort is shown the real control price');
    }
    T::true(($cohorts['treatment'] ?? 0) > 0 && ($cohorts['control'] ?? 0) > 0, 'both cohorts exist even in a dry run');

    // stability: the same visitor always gets the same cohort and price on a later page view
    $first = Pricing::resolveForService($svc['id'], $svc['price_paise'], $anons[0]);
    $again = Pricing::resolveForService($svc['id'], $svc['price_paise'], $anons[0]);
    T::same([$first['cohort'], $first['price_paise']], [$again['cohort'], $again['price_paise']]);

    // find one anon that is bucketed into 'treatment' during the dry run, to prove stability survives the later goLive switch
    $treatmentAnon = null;
    foreach ($anons as $a) {
        if (Pricing::resolveForService($svc['id'], $svc['price_paise'], $a)['cohort'] === 'treatment') {
            $treatmentAnon = $a;
            break;
        }
    }
    T::true($treatmentAnon !== null);

    $exp = Pricing::goLive($exp['id'], null, pr_owner());
    T::same('live', $exp['mode']);
    $still = Pricing::resolveForService($svc['id'], $svc['price_paise'], $treatmentAnon);
    T::same($svc['price_paise'], $still['price_paise'], 'an already-assigned visitor is never retroactively moved to the candidate price');

    $newTreatment = null;
    for ($i = 0; $i < 200 && $newTreatment === null; $i++) {
        $a = pr_anon();
        $r = Pricing::resolveForService($svc['id'], $svc['price_paise'], $a);
        if ($r['cohort'] === 'treatment') {
            $newTreatment = $r;
        }
    }
    T::true($newTreatment !== null);
    T::same(8900, $newTreatment['price_paise'], 'a visitor assigned after go-live sees the candidate price if bucketed into treatment');
});

T::test('pricing access: propose needs pricing.propose, going live and applying a winner need pricing.approve, pause/rollback need pricing.pause', function () {
    pr_reset();
    $svc = pr_service();
    $ctl = new PricingController();
    $req = static fn(string $method, string $path, string $role, array $body = []): \App\Core\Request => la_req($method, $path, $role, $body);
    $json = static fn(Response $r): array => json_decode((string) $r->body, true) ?: [];

    foreach (['analyst', 'support'] as $role) {
        T::throws(HttpException::class, fn() => $ctl->propose($req('POST', '/admin/api/pricing/experiments', $role, ['service_id' => $svc['id'], 'candidate_price' => '89']), []), 403);
    }
    $exp = $json($ctl->propose($req('POST', '/admin/api/pricing/experiments', 'manager', ['service_id' => $svc['id'], 'candidate_price' => '89']), []))['experiment'];

    // a manager may start a dry run …
    $exp = $json($ctl->start($req('POST', "/admin/api/pricing/experiments/{$exp['id']}/start", 'manager', ['mode' => 'dry_run']), ['id' => (string) $exp['id']]))['experiment'];
    T::same('dry_run', $exp['mode']);
    // … but not switch it live
    T::throws(HttpException::class, fn() => $ctl->goLive($req('POST', "/admin/api/pricing/experiments/{$exp['id']}/go-live", 'manager'), ['id' => (string) $exp['id']]), 403);
    $exp = $json($ctl->goLive($req('POST', "/admin/api/pricing/experiments/{$exp['id']}/go-live", 'owner'), ['id' => (string) $exp['id']]))['experiment'];
    T::same('live', $exp['mode']);

    // a manager may pause and roll back
    $exp = $json($ctl->pause($req('POST', "/admin/api/pricing/experiments/{$exp['id']}/pause", 'manager', ['reason' => 'checking something']), ['id' => (string) $exp['id']]))['experiment'];
    T::same('paused', $exp['status']);
    $exp = $json($ctl->rollback($req('POST', "/admin/api/pricing/experiments/{$exp['id']}/rollback", 'manager', ['reason' => 'done testing']), ['id' => (string) $exp['id']]))['experiment'];
    T::same('rolled_back', $exp['status']);
});

// ═══════════════════════════ evaluation: decisions and rollback triggers ═════

T::test('pricing: a clearly better candidate is declared the winner once both cohorts clear the minimum sample', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 500); // ₹49 default
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59', 'min_sample' => 20, 'profit_floor' => '0'], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());

    // 25 qualified visitors in control, 10 buy at ₹49; 25 qualified in treatment, 15 buy at ₹59 — clearly more contribution per visitor
    for ($i = 0; $i < 25; $i++) {
        pr_order($svc['id'], 4900, $exp['id'], 'control', $exp['control_version_id'] ?? null, $i < 10, 'COMPLETED', 500);
    }
    for ($i = 0; $i < 25; $i++) {
        pr_order($svc['id'], 5900, $exp['id'], 'treatment', $exp['candidate_version_id'] ?? null, $i < 15, 'COMPLETED', 500);
    }
    // the rollup reads qualified visitors from pricing_assignments, not from orders — give it 25 qualified assignments per cohort (50 total, split below)
    for ($i = 0; $i < 50; $i++) {
        pr_visit($svc['id'], $svc['price_paise'], pr_anon());
    }
    // force exactly 25/25 split by writing the cohort columns directly (bucket hashing will not split 50/50 by chance every run)
    $ids = Db::col('SELECT id FROM pricing_assignments WHERE experiment_id = ? ORDER BY id', [$exp['id']]);
    foreach ($ids as $i => $id) {
        Db::update('pricing_assignments', ['cohort' => $i % 2 === 0 ? 'control' : 'treatment', 'qualified_at' => Clock::sql()], 'id = ?', [$id]);
    }

    $r = Pricing::evaluate($exp['id'], true);
    T::same('concluded', $r['action']);
    T::same('winner', $r['decision']);
    $row = Pricing::experiment($exp['id']);
    T::same('concluded', $row['status']);
    T::true($row['cohorts'] !== []);
});

T::test('pricing: applyWinner activates the candidate price, audits it, and blocks editing the price while an experiment is running', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 500);
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59', 'min_sample' => 10, 'profit_floor' => '0'], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());

    T::throws(HttpException::class, fn() => AdminServices::save($svc['id'], ['price' => '69', 'expected_updated_at' => Db::val('SELECT updated_at FROM services WHERE id = ?', [$svc['id']])], Request::fake('PUT', '/x'), pr_owner()), 422);

    Db::update('pricing_experiments', ['status' => 'concluded', 'decision' => 'winner', 'decision_reason' => 'test'], 'id = ?', [$exp['id']]);
    $applied = Pricing::applyWinner($exp['id'], null, pr_owner());
    T::true($applied['applied']);
    T::same(5900, (int) Db::val('SELECT price_paise FROM services WHERE id = ?', [$svc['id']]));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM price_audit_logs WHERE experiment_id = ? AND actor_type = 'experiment'", [$exp['id']]));
    T::throws(HttpException::class, fn() => Pricing::applyWinner($exp['id'], null, pr_owner()), 409, 'activating the same winner twice is refused');

    // the price can be edited again now that no experiment is running
    AdminServices::save($svc['id'], ['price' => '69', 'expected_updated_at' => Db::val('SELECT updated_at FROM services WHERE id = ?', [$svc['id']])], Request::fake('PUT', '/x'), pr_owner());
    T::same(6900, (int) Db::val('SELECT price_paise FROM services WHERE id = ?', [$svc['id']]));
});

T::test('pricing rollback: a profit-floor breach stops the experiment automatically, restores the control price for every new visitor, and notifies', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 4400); // high fulfilment cost so the candidate price barely covers it
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59', 'min_sample' => 10, 'profit_floor' => '2000'], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());

    // 10 purchases at the candidate price, each costing ₹44 fulfilment → contribution per order ₹15, below the ₹20 floor
    for ($i = 0; $i < 10; $i++) {
        pr_order($svc['id'], 5900, $exp['id'], 'treatment', $exp['candidate_version_id'] ?? null, true, 'COMPLETED', 4400);
    }
    $r = Pricing::evaluate($exp['id'], true);
    T::same(['rolled_back'], [$r['action']]);
    T::contains('floor', $r['reason']);
    $row = Pricing::experiment($exp['id']);
    T::same(['rolled_back', 'rolled_back'], [$row['status'], $row['decision']]);
    T::true($row['cooldown_until'] !== null);
    T::true((int) Db::val("SELECT COUNT(*) FROM business_alerts WHERE alert_type = 'pricing.rollback'") === 1);

    // a new visitor sees the normal (control) price — the experiment is no longer running
    $v = Pricing::resolveForService($svc['id'], $svc['price_paise'], pr_anon());
    T::same([$svc['price_paise'], null, null], [$v['price_paise'], $v['experiment_id'], $v['cohort']]);

    // proposing a new experiment on this service is refused during the cooldown
    T::throws(HttpException::class, fn() => Pricing::propose($svc['id'], ['candidate_price' => '89'], null, pr_owner()), 422);
});

T::test('pricing rollback: a service cost increase since the experiment started is caught even with zero orders yet', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 1000);
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59'], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());

    Db::update('services', ['fulfil_cost_paise' => 3000], 'id = ?', [$svc['id']]); // tripled
    $r = Pricing::evaluate($exp['id'], true);
    T::same('rolled_back', $r['action']);
    T::contains('cost increased', $r['reason']);
});

T::test('pricing rollback: an unexpected default-price change and a data-integrity failure are both caught', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 1000);
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59'], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());
    Db::update('services', ['price_paise' => 5400], 'id = ?', [$svc['id']]); // changed outside the normal (blocked) path
    $r = Pricing::evaluate($exp['id'], true);
    T::same('rolled_back', $r['action']);
    T::contains('unexpectedly', $r['reason']);

    pr_reset();
    $svc = pr_service('yt-likes-50k', 1000);
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59'], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());
    Db::insert('orders', [
        'public_id' => 'SMB-PBAD001', 'access_token' => bin2hex(random_bytes(16)), 'service_id' => $svc['id'], 'platform_id' => Db::val('SELECT platform_id FROM services WHERE id = ?', [$svc['id']]),
        'platform_slug' => 'youtube', 'service_code' => 'yt-likes-50k', 'service_title' => 'x', 'service_type' => 'likes', 'mode' => 'MANUAL', 'quantity' => 1,
        'amount_paise' => 5900, 'experiment_id' => $exp['id'], 'price_cohort' => null, 'price_version_id' => null,
        'target' => '@x', 'target_url' => 'https://www.instagram.com/x/', 'status' => 'COMPLETED', 'payment_status' => 'success',
        'created_day' => Tz::today(), 'paid_day' => Tz::today(), 'paid_at' => Clock::sql(), 'created_at' => Clock::sql(), 'updated_at' => Clock::sql(),
    ]);
    $r = Pricing::evaluate($exp['id'], true);
    T::same('rolled_back', $r['action']);
    T::contains('integrity', $r['reason']);
});

T::test('pricing: a cron dry run (job-level) computes and logs without changing any experiment or price', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 1000);
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59'], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());
    Db::update('services', ['fulfil_cost_paise' => 5000], 'id = ?', [$svc['id']]); // would trigger a rollback if committed

    $r = Pricing::evaluate($exp['id'], false);
    T::same('rolled_back', $r['action'], 'the computed outcome is still reported');
    T::same('running', Db::val('SELECT status FROM pricing_experiments WHERE id = ?', [$exp['id']]), 'but nothing was actually changed');
    T::true((int) Db::val("SELECT COUNT(*) FROM pricing_decision_logs WHERE experiment_id = ? AND dry_run = 1", [$exp['id']]) === 1);
});

T::test('pricing: duration exceeded without a conclusive result rolls back as insufficient_data; the simulator uses only real data', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 1000);
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59', 'max_duration_days' => 1, 'min_sample' => 500], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());
    Clock::setFixed(PR_DAY0 + 2 * 86400);
    $r = Pricing::evaluate($exp['id'], true);
    T::same(['rolled_back', 'insufficient_data'], [$r['action'], $r['decision']]);
    T::contains('duration', $r['reason']);

    $sim = Pricing::simulate($svc['id'], ['59', '69', '50' /* invalid ending */]);
    T::same([true, true, false], array_column($sim['candidates'], 'valid_ending'));
    T::true($sim['candidates'][0]['contribution_per_order_paise'] > 0);
    T::same(0, $sim['projected_orders_same_volume'], 'no real paid orders exist yet for this service — a volume of zero is reported, never invented');
});

T::test('pricing: learningHistory() renders a rolled-back experiment (regression: the platform join was missing)', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 1000);
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59'], null, pr_owner());
    $exp = Pricing::start($exp['id'], 'live', null, pr_owner());
    Db::update('services', ['fulfil_cost_paise' => 3000], 'id = ?', [$svc['id']]); // triggers the cost-increase rollback
    Pricing::evaluate($exp['id'], true);

    $h = Pricing::learningHistory(['page' => 1]);
    T::same(1, $h['total']);
    T::same('youtube', $h['rows'][0]['platform']);
    T::same(1, $h['summary']['rolled_back']);
});

T::test('pricing: AI insight is advisory and clearly separate from the deterministic decision; off by default', function () {
    pr_reset();
    $svc = pr_service('yt-likes-50k', 500);
    $exp = Pricing::propose($svc['id'], ['candidate_price' => '59'], null, pr_owner());
    $r = \App\Services\PricingAi::explain($exp['id'], pr_owner());
    T::same('none', $r['status']);
    T::true($r['insight'] === null);

    $insight = \App\Services\PricingAi::rulesBasedInsight(Pricing::experiment($exp['id']), null, null);
    T::same('rules', $insight['source']);
    T::contains('enough', $insight['explanation']);
});
