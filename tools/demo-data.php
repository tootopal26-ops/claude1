<?php
/**
 * DEV ONLY: fills the (local) database with ~75 days of realistic traffic, orders and payments so the admin
 * dashboard, charts and tables can be reviewed with real-looking data.
 *
 *   php tools/demo-data.php [days=75]
 *
 * It WIPES orders, payments, visitors, analytics and webhook rows. Refuses to run unless APP_ENV=local.
 * Deterministic (seeded) so screenshots and tests are repeatable.
 */
declare(strict_types=1);

if (!defined('SMB_ROOT')) {
    require dirname(__DIR__) . '/app/bootstrap.php';
}
if (!App\Core\Env::isLocal()) {
    fwrite(STDERR, "Refusing to run: APP_ENV is not 'local'.\n");
    exit(1);
}

use App\Core\Db;
use App\Services\Analytics;
use App\Services\Orders;
use App\Support\Clock;
use App\Support\Tz;

$days = max(7, min(400, is_numeric($argv[1] ?? null) ? (int) $argv[1] : 75));
mt_srand(20261014);
$pick = static function (array $weights): string {
    $total = array_sum($weights);
    $r = mt_rand(1, (int) ($total * 1000)) / 1000;
    foreach ($weights as $k => $w) {
        if (($r -= $w) <= 0) {
            return (string) $k;
        }
    }
    return (string) array_key_first($weights);
};
$chance = static fn(float $p): bool => mt_rand(0, 999_999) / 1_000_000 < $p;
$utr = static fn(): string => (string) mt_rand(100000000000, 999999999999);
$rand = static fn(int $n): string => substr(bin2hex(random_bytes((int) ceil($n / 2))), 0, $n);

echo "Wiping previous demo data…\n";
Db::exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['order_status_history', 'smm_transactions', 'manual_orders', 'webhook_events', 'payments', 'orders', 'users', 'visitor_days', 'visitor_sessions', 'visitors', 'analytics_daily', 'idempotency_keys', 'rate_limits'] as $t) {
    Db::exec("TRUNCATE TABLE `{$t}`");
}
Db::exec('SET FOREIGN_KEY_CHECKS = 1');

// ── catalogue: make a realistic AUTO / MANUAL mix with provider costs ──
$provider = Db::one('SELECT id FROM smm_providers ORDER BY is_default DESC, id LIMIT 1');
$costs = [ // code => [mode, cost paise]
    'ig-followers-50' => ['AUTO', 150], 'ig-followers-100k' => ['MANUAL', 0], 'ig-likes-100' => ['AUTO', 120], 'ig-likes-50k' => ['AUTO', 1350],
    'ig-views-1k' => ['AUTO', 90], 'ig-views-1m' => ['MANUAL', 0], 'ig-blue-tick' => ['MANUAL', 0],
    'yt-subs-50' => ['AUTO', 210], 'yt-subs-100k' => ['MANUAL', 0], 'yt-likes-100' => ['AUTO', 140], 'yt-likes-50k' => ['MANUAL', 0],
    'yt-views-10' => ['AUTO', 60], 'yt-views-10k' => ['AUTO', 4400], 'yt-watch-4000h' => ['MANUAL', 0],
];
foreach ($costs as $code => [$mode, $cost]) {
    Db::update('services', [
        'mode' => $mode, 'smm_cost_paise' => $cost, 'smm_provider_id' => $mode === 'AUTO' && $provider ? $provider['id'] : null,
        'smm_service_id' => $mode === 'AUTO' ? (string) (1000 + crc32($code) % 900) : null,
    ], 'code = ?', [$code]);
}
$services = Db::all('SELECT s.*, p.slug AS pslug FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.deleted_at IS NULL');
$weightByCode = [
    'ig-followers-50' => 22, 'ig-followers-100k' => 8, 'ig-likes-100' => 14, 'ig-likes-50k' => 5, 'ig-views-1k' => 10, 'ig-views-1m' => 3, 'ig-blue-tick' => 2,
    'yt-subs-50' => 12, 'yt-subs-100k' => 4, 'yt-likes-100' => 8, 'yt-likes-50k' => 3, 'yt-views-10' => 7, 'yt-views-10k' => 4, 'yt-watch-4000h' => 2,
];
$svcWeights = [];
foreach ($services as $s) {
    $svcWeights[$s['id']] = $weightByCode[$s['code']] ?? 1;
}
$svcById = array_column($services, null, 'id');

// ad CPC history: ₹0.95 until 30 days ago, ₹1.08 since
Db::exec('DELETE FROM advertisement_settings');
Db::insert('advertisement_settings', ['cpc_paise' => 95, 'qualify_seconds' => 3, 'assumed_conversion_bps' => 400, 'created_at' => Clock::sql(Clock::now() - 200 * 86400)]);
Db::insert('advertisement_settings', ['cpc_paise' => 108, 'qualify_seconds' => 3, 'assumed_conversion_bps' => 400, 'created_at' => Clock::sql(Clock::now() - 30 * 86400)]);

$today = Tz::today();
$nowTs = Clock::now();
$users = ['@riya.official', '@aman_vlogs', '@fitwithkaran', '@the.neha.show', '@travel_with_sid', '@dailyreels.in', '@priya_makeup', 'https://www.instagram.com/p/C8xYz12AbCd/',
    'https://www.youtube.com/@techwithraj', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.instagram.com/reel/C9aBcDeFg12/', '@gamerboy_07'];
$stats = ['orders' => 0, 'paid' => 0, 'visitors' => 0, 'manual_pending' => 0];

for ($i = $days - 1; $i >= 0; $i--) {
    $day = Tz::addDays($today, -$i);
    $dayStart = Tz::dayStart($day);
    $isToday = $i === 0;
    $dow = (int) (new DateTimeImmutable($day))->format('N');
    $trend = 1 + (($days - $i) / $days) * 0.65;                   // traffic grows over time
    $weekend = $dow >= 6 ? 1.18 : 1.0;
    $nVisitors = (int) round((70 + mt_rand(0, 70)) * $trend * $weekend);
    $cpc = $i > 30 ? 95 : 108;
    $horizon = $isToday ? max(3600, $nowTs - $dayStart) : 86400;  // today is only partly elapsed
    if ($isToday) {
        $nVisitors = (int) round($nVisitors * min(1, $horizon / 86400) * 1.2);
    }

    $dayVisitors = [];
    Db::tx(static function () use ($nVisitors, $day, $dayStart, $horizon, $cpc, $pick, $chance, $rand, &$dayVisitors, &$stats): void {
        for ($v = 0; $v < $nVisitors; $v++) {
            $ts = $dayStart + (int) (pow(mt_rand(0, 1000) / 1000, 0.8) * $horizon);    // evening-heavy
            $source = $pick(['instagram' => 38, 'youtube' => 27, 'direct' => 25, 'other' => 10]);
            $device = $pick(['mobile' => 71, 'desktop' => 24, 'tablet' => 5]);
            $qualified = $chance(0.62);
            $vid = Db::insert('visitors', [
                'anon_id' => $rand(32), 'first_seen_at' => Clock::sql($ts), 'last_seen_at' => Clock::sql($ts + 60), 'first_landing_path' => '/', 'first_source' => $source,
                'device_type' => $device, 'browser' => $device === 'desktop' ? 'Chrome' : 'Chrome', 'os' => $device === 'desktop' ? 'Windows' : 'Android', 'sessions_count' => 1,
            ]);
            Db::insert('visitor_days', [
                'visitor_id' => $vid, 'day' => $day, 'qualified' => $qualified ? 1 : 0, 'qualified_at' => $qualified ? Clock::sql($ts + 4) : null,
                'cpc_paise' => $qualified ? $cpc : 0, 'source' => $source, 'device_type' => $device, 'created_at' => Clock::sql($ts),
            ]);
            $dayVisitors[] = ['id' => $vid, 'ts' => $ts, 'qualified' => $qualified];
            $stats['visitors']++;
        }
    });

    // orders: a share of qualified visitors starts checkout
    $qualified = array_values(array_filter($dayVisitors, static fn($v) => $v['qualified']));
    $nOrders = (int) round(count($qualified) * (0.05 + mt_rand(0, 40) / 1000));
    for ($o = 0; $o < $nOrders && $qualified; $o++) {
        $vis = $qualified[array_rand($qualified)];
        $svc = $svcById[(int) $pick($svcWeights)];
        $createdTs = min($nowTs - 30, $vis['ts'] + mt_rand(20, 600));
        $outcome = $pick(['paid' => 78, 'expired' => 16, 'failed' => 6]);
        if ($createdTs > $nowTs - 300) {
            $outcome = 'pending';
        }
        $mode = (string) $svc['mode'];
        $paidTs = $outcome === 'paid' ? $createdTs + mt_rand(25, 270) : null;
        $amount = (int) $svc['price_paise'];
        $age = $nowTs - $createdTs;

        // final status
        $status = match ($outcome) {
            'pending' => 'PENDING_PAYMENT', 'expired' => 'EXPIRED', 'failed' => 'FAILED',
            default => (function () use ($mode, $age, $pick, $chance): string {
                if ($mode === 'MANUAL') {
                    return $age < 6 * 86400 && $chance(0.85) ? 'MANUAL_PENDING' : $pick(['MANUAL_APPROVED' => 60, 'COMPLETED' => 34, 'REFUNDED' => 4, 'CANCELLED' => 2]);
                }
                if ($age < 6 * 3600) {
                    return $pick(['SUBMITTED' => 30, 'IN_PROGRESS' => 50, 'COMPLETED' => 20]);
                }
                return $pick(['COMPLETED' => 88, 'PARTIAL' => 4, 'FAILED' => 3, 'REFUNDED' => 3, 'IN_PROGRESS' => 2]);
            })(),
        };
        $refunded = $status === 'REFUNDED' ? $amount : ($status === 'PARTIAL' ? (int) round($amount * 0.25 / 100) * 100 : 0);
        $payStatus = $outcome === 'paid' ? ($status === 'REFUNDED' ? 'refunded' : ($refunded > 0 ? 'partially_refunded' : 'success')) : ($outcome === 'pending' ? 'pending' : $outcome);
        $cost = $mode === 'AUTO' && $outcome === 'paid' && $status !== 'FAILED' ? (int) $svc['smm_cost_paise'] : 0;
        $platform = (string) $svc['pslug'];
        $igUsers = ['@riya.official', '@aman_vlogs', '@fitwithkaran', '@the.neha.show', '@travel_with_sid', '@dailyreels.in', '@priya_makeup', '@gamerboy_07'];
        $ytChannels = ['@techwithraj', '@cookwithmeera', '@gamingwithkabir', '@fitnessbyarjun', '@musicbyisha'];
        $target = match (true) {
            $platform === 'youtube' && $svc['target_kind'] === 'video' => 'https://www.youtube.com/watch?v=' . $rand(11),
            $platform === 'youtube' => $ytChannels[array_rand($ytChannels)],
            $svc['target_kind'] === 'post' => 'https://www.instagram.com/' . (mt_rand(0, 1) ? 'p' : 'reel') . '/' . $rand(11) . '/',
            default => $igUsers[array_rand($igUsers)],
        };
        $targetUrl = str_starts_with($target, 'http') ? $target : ($platform === 'youtube' ? 'https://www.youtube.com/' . $target : 'https://www.instagram.com/' . ltrim($target, '@') . '/');
        $providerOrder = $mode === 'AUTO' && $outcome === 'paid' && $status !== 'FAILED' ? (string) mt_rand(7000000, 9999999) : null;

        Db::tx(static function () use ($vis, $svc, $day, $createdTs, $paidTs, $amount, $status, $payStatus, $refunded, $cost, $platform, $target, $targetUrl, $providerOrder, $mode, $outcome, $provider, $rand, $utr, $chance, &$stats, $nowTs): void {
            $paidDay = $paidTs ? Tz::dayOf($paidTs) : null;
            $orderId = Db::insert('orders', [
                'public_id' => Orders::newPublicId(), 'access_token' => $rand(32), 'visitor_id' => $vis['id'], 'service_id' => $svc['id'], 'platform_id' => $svc['platform_id'],
                'platform_slug' => $platform, 'service_code' => $svc['code'], 'service_title' => $svc['title'], 'service_type' => $svc['type'], 'mode' => $mode, 'quantity' => $svc['quantity'],
                'amount_paise' => $amount, 'target' => $target, 'target_url' => $targetUrl, 'status' => $status, 'payment_status' => $payStatus, 'refunded_paise' => $refunded,
                'late_payment' => 0, 'smm_provider_id' => $mode === 'AUTO' ? ($provider['id'] ?? null) : null, 'smm_service_id' => $mode === 'AUTO' ? $svc['smm_service_id'] : null,
                'provider_order_id' => $providerOrder, 'provider_status' => $providerOrder ? match ($status) { 'COMPLETED' => 'Completed', 'PARTIAL' => 'Partial', 'IN_PROGRESS' => 'In progress', default => 'Pending' } : null,
                'estimated_cost_paise' => $mode === 'AUTO' ? (int) $svc['smm_cost_paise'] : 0, 'provider_cost_paise' => $cost, 'provider_cost_final' => $providerOrder && !in_array($status, ['SUBMITTED', 'IN_PROGRESS'], true) ? 1 : 0,
                'provider_attempts' => $providerOrder ? 1 : 0, 'last_error' => $status === 'FAILED' && $outcome === 'paid' ? 'Provider rejected the order: Incorrect link' : null,
                'failure_kind' => $status === 'FAILED' && $outcome === 'paid' ? 'rejected' : null,
                'created_day' => Tz::dayOf($createdTs), 'paid_day' => $paidDay, 'paid_at' => $paidTs ? Clock::sql($paidTs) : null,
                'submitted_at' => $providerOrder ? Clock::sql($paidTs + 6) : null, 'completed_at' => in_array($status, ['COMPLETED', 'MANUAL_APPROVED'], true) ? Clock::sql(min($nowTs, ($paidTs ?? $createdTs) + mt_rand(600, 40000))) : null,
                'created_at' => Clock::sql($createdTs), 'updated_at' => Clock::sql(min($nowTs, ($paidTs ?? $createdTs) + 600)),
            ]);
            $payId = Db::insert('payments', [
                'ref' => $rand(32), 'order_id' => $orderId, 'gateway_id' => 1, 'provider' => 'alpha_upi', 'gateway_order_id' => 'SMB' . strtoupper($rand(12)),
                'provider_txn_id' => $paidTs ? 'UTR' . $utr() : null, 'gateway_method' => $paidTs ? (mt_rand(0, 1) ? 'bharatpe' : 'paytm') : null, 'amount_paise' => $amount,
                'status' => $payStatus === 'pending' ? 'pending' : ($outcome === 'paid' ? $payStatus : $outcome), 'expires_at' => Clock::sql($createdTs + 300), 'paid_at' => $paidTs ? Clock::sql($paidTs) : null,
                'paid_day' => $paidDay, 'paid_amount_paise' => $paidTs ? $amount : null, 'verified_via' => $paidTs ? ($chance(0.85) ? 'webhook' : 'poll') : null, 'late' => 0,
                'failure_reason' => $outcome === 'failed' ? 'Payment declined by the bank' : null, 'refunded_paise' => $refunded, 'poll_count' => mt_rand(1, 40),
                'created_day' => Tz::dayOf($createdTs), 'created_at' => Clock::sql($createdTs), 'updated_at' => Clock::sql($createdTs + 60),
            ]);
            $hist = [[null, 'PENDING_PAYMENT', 'customer', 'Order created', $createdTs]];
            if ($paidTs) {
                $hist[] = ['PENDING_PAYMENT', $mode === 'MANUAL' ? 'MANUAL_PENDING' : 'PAID', 'webhook', 'Payment confirmed', $paidTs];
                if ($mode === 'AUTO' && $providerOrder) {
                    $hist[] = ['PAID', 'SUBMITTED', 'system', 'Submitted to provider (order ' . $providerOrder . ')', $paidTs + 6];
                }
                if ($status !== 'MANUAL_PENDING' && $status !== 'SUBMITTED' && !($mode === 'AUTO' && $status === 'PAID')) {
                    $from = $mode === 'AUTO' ? ($providerOrder ? 'SUBMITTED' : 'PAID') : 'MANUAL_PENDING';
                    $hist[] = [$from, $status, $mode === 'MANUAL' ? 'admin' : 'provider', $mode === 'MANUAL' ? 'Approved' : 'Provider status update', min($nowTs, $paidTs + mt_rand(900, 40000))];
                }
            } elseif ($status !== 'PENDING_PAYMENT') {
                $hist[] = ['PENDING_PAYMENT', $status, 'system', $status === 'EXPIRED' ? 'Payment session expired' : 'Payment failed', $createdTs + 300];
            }
            foreach ($hist as [$from, $to, $actor, $note, $ts]) {
                Db::insert('order_status_history', ['order_id' => $orderId, 'from_status' => $from, 'to_status' => $to, 'actor_type' => $actor, 'actor_label' => $actor === 'admin' ? 'owner' : null, 'note' => $note, 'created_at' => Clock::sql($ts)]);
            }
            if ($providerOrder) {
                Db::insert('smm_transactions', [
                    'order_id' => $orderId, 'provider_id' => $provider['id'] ?? null, 'action' => 'add', 'request_json' => json_encode(['service' => $svc['smm_service_id'], 'link' => $targetUrl, 'quantity' => $svc['quantity']]),
                    'response_body' => json_encode(['order' => (int) $providerOrder]), 'http_status' => 200, 'ok' => 1, 'provider_order_id' => $providerOrder, 'cost_paise' => $cost, 'duration_ms' => mt_rand(180, 900), 'created_at' => Clock::sql($paidTs + 6),
                ]);
            }
            if ($mode === 'MANUAL' && $paidTs) {
                $state = $status === 'MANUAL_PENDING' ? 'pending' : ($status === 'CANCELLED' ? 'rejected' : 'approved');
                Db::insert('manual_orders', ['order_id' => $orderId, 'state' => $state, 'approved_by' => $state === 'approved' ? 1 : null, 'approved_at' => $state === 'approved' ? Clock::sql($paidTs + 3600) : null, 'created_at' => Clock::sql($paidTs), 'updated_at' => Clock::sql($paidTs)]);
                if ($state === 'pending') {
                    $stats['manual_pending']++;
                }
            }
            if ($paidTs && $chance(0.85)) {
                $eid = 'evt_' . $rand(20);
                Db::insert('webhook_events', [
                    'gateway_id' => 1, 'provider' => 'alpha_upi', 'event_id' => $eid, 'event_type' => 'payment.success', 'dedupe_key' => 'alpha_upi:' . $eid, 'signature_valid' => 1,
                    'payload_hash' => hash('sha256', $eid), 'payload' => json_encode(['order_id' => $orderId, 'status' => 'SUCCESS', 'amount' => $amount / 100]), 'status' => 'processed',
                    'payment_id' => $payId, 'order_id' => $orderId, 'ip' => '203.0.113.' . mt_rand(2, 250), 'attempts' => 1, 'received_at' => Clock::sql($paidTs + 2), 'processed_at' => Clock::sql($paidTs + 2),
                ]);
            }
            Db::exec('UPDATE visitor_days SET order_created = 1, payment_succeeded = ? WHERE visitor_id = ? AND day = ?', [$paidTs ? 1 : 0, $vis['id'], Tz::dayOf($vis['ts'])]);
            $stats['orders']++;
            $stats['paid'] += $paidTs ? 1 : 0;
        });
    }
}

// a couple of rejected webhooks and an audit trail for the activity feed
foreach ([['bad signature', 'invalid', 0], ['duplicate delivery', 'duplicate', 1]] as $k => [$err, $st, $sig]) {
    $eid = 'evt_x' . $k . $rand(12);
    Db::insert('webhook_events', ['gateway_id' => 1, 'provider' => 'alpha_upi', 'event_id' => $eid, 'event_type' => 'payment.success', 'dedupe_key' => 'alpha_upi:' . $eid, 'signature_valid' => $sig,
        'payload_hash' => hash('sha256', $eid), 'status' => $st, 'error' => $err, 'ip' => '198.51.100.7', 'attempts' => 1, 'received_at' => Clock::sql($nowTs - 3600 * ($k + 2))]);
}
Db::exec('DELETE FROM audit_logs WHERE action <> ?', ['admin.login']);
foreach ([
    ['service.updated', 'service', '2', ['code' => 'ig-followers-100k', 'changed' => ['smm_cost_paise']], 5 * 86400],
    ['service.price_changed', 'service', '1', ['code' => 'ig-followers-50', 'from' => '5.00', 'to' => '5.00'], 9 * 86400],
    ['settings.advertisement', 'advertisement', null, ['cpc_from' => '0.95', 'cpc_to' => '1.08', 'qualify_seconds' => 3], 30 * 86400],
    ['smm.provider_saved', 'smm_provider', '1', ['created' => false, 'changed' => ['rate_to_inr']], 12 * 86400],
    ['order.approve_all', 'order', null, ['approved' => 14], 2 * 86400],
    ['gateway.saved', 'payment_gateway', '1', ['changed' => ['environment']], 20 * 86400],
] as [$action, $et, $eid, $meta, $ago]) {
    Db::insert('audit_logs', ['admin_id' => 1, 'admin_label' => 'owner', 'action' => $action, 'entity_type' => $et, 'entity_id' => $eid, 'ip' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0 Chrome/120', 'meta_json' => json_encode($meta), 'created_at' => Clock::sql($nowTs - $ago)]);
}

echo "Rebuilding analytics…\n";
$n = Analytics::rebuildAll();
printf("Done: %d visitors, %d orders (%d paid, %d waiting for manual approval); %d days rolled up.\n", $stats['visitors'], $stats['orders'], $stats['paid'], $stats['manual_pending'], $n);
