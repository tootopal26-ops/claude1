<?php
// QA helper (local only): make the Business Watchdog find real problems in the dev database, then run the REAL watchdog job once, so the alerts the browser
// suite looks at were raised by the real checks from real rows — nothing is written into business_alerts by hand.   php tools/qa/alerts-seed.php  → JSON
//   payments     10 failed + 4 verified payments in the last 45 minutes                → "Many payments are failing"
//   provider     the default SMM provider failed 6 of its last 7 order calls          → "SMM provider … is failing"
//   stuck        a paid AUTO order that was never sent, paid over an hour ago          → "Paid orders are stuck"
//   jobs         three failed runs of the cleanup job in the last hour                 → "“Cleanup and retention” keeps failing"
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }

use App\Core\Db;
use App\Services\Jobs;
use App\Services\Settings;
use App\Support\Clock;
use App\Support\Tz;

$now = Clock::now();
$svc = Db::one("SELECT s.*, p.slug FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.code = 'ig-followers-100k'") ?? throw new RuntimeException('no service');
$n = 0;
$order = static function (string $status, string $mode, int $paidAgo, ?int $createdAgo = null) use (&$n, $svc, $now): int {
    $n++;
    $created = $now - ($createdAgo ?? $paidAgo + 30);
    $paid = $status !== 'PENDING_PAYMENT';
    return Db::insert('orders', [
        'public_id' => 'SMB-AL' . str_pad((string) $n, 6, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(1))), 'access_token' => bin2hex(random_bytes(16)), 'service_id' => $svc['id'], 'platform_id' => $svc['platform_id'],
        'platform_slug' => $svc['slug'], 'service_code' => $svc['code'], 'service_title' => $svc['title'], 'service_type' => $svc['type'], 'mode' => $mode, 'quantity' => (int) $svc['quantity'],
        'amount_paise' => 9900, 'target' => '@alerts_qa', 'target_url' => 'https://www.instagram.com/alerts_qa/', 'status' => $status, 'payment_status' => $paid ? 'success' : 'pending',
        'created_day' => Tz::dayOf($created), 'paid_day' => $paid ? Tz::dayOf($now - $paidAgo) : null, 'paid_at' => $paid ? Clock::sql($now - $paidAgo) : null,
        'created_at' => Clock::sql($created), 'updated_at' => Clock::sql($created),
    ]);
};
$payment = static function (int $orderId, string $status, int $agoSeconds) use (&$n): void {
    $n++;
    $paid = $status === 'success';
    $at = Clock::now() - $agoSeconds;
    Db::insert('payments', [
        'ref' => bin2hex(random_bytes(16)), 'order_id' => $orderId, 'provider' => 'alpha_upi', 'gateway_order_id' => 'ALQA' . $n . bin2hex(random_bytes(3)), 'amount_paise' => 9900,
        'paid_amount_paise' => $paid ? 9900 : null, 'status' => $status, 'expires_at' => Clock::sql($at + 300), 'paid_at' => $paid ? Clock::sql($at + 20) : null, 'paid_day' => $paid ? Tz::dayOf($at) : null,
        'created_day' => Tz::dayOf($at), 'created_at' => Clock::sql($at), 'updated_at' => Clock::sql($at),
    ]);
};

for ($i = 0; $i < 10; $i++) {
    $payment($order('PENDING_PAYMENT', 'MANUAL', 0, 1500 + $i * 60), 'failed', 1500 + $i * 60);
}
for ($i = 0; $i < 4; $i++) {
    $payment($order('MANUAL_PENDING', 'MANUAL', 1500 + $i * 60 - 20, 1500 + $i * 60), 'success', 1500 + $i * 60);
}

$provider = Db::one('SELECT id FROM smm_providers WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
$providerId = $provider !== null ? (int) $provider['id'] : Db::insert('smm_providers', ['name' => 'Alerts QA panel', 'api_url' => 'http://127.0.0.1:4020/api/v2', 'api_key_enc' => 'x', 'is_active' => 1, 'is_default' => 1, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()]);
foreach ([[0, null, 'Network error (28): Operation timed out'], [0, null, 'Network error (7): Failed to connect'], [0, 502, 'The provider reported a server error (HTTP 502).'], [0, 200, 'Incorrect link'], [0, 200, 'Not enough funds'], [0, 200, 'Incorrect link'], [1, 200, '']] as $i => [$ok, $http, $err]) {
    Db::insert('smm_transactions', ['provider_id' => $providerId, 'action' => 'add', 'ok' => $ok, 'http_status' => $http, 'error' => $err !== '' ? $err : null, 'created_at' => Clock::sql($now - 600 - $i * 30)]);
}

$stuck = $order('PAID', 'AUTO', 4000);                       // paid 66 minutes ago and never sent — with its verified payment (created before the one-hour window of the payment-failure check)
$payment($stuck, 'success', 4100);
Db::update('orders', ['smm_provider_id' => $providerId, 'smm_service_id' => '1001'], 'id = ?', [$stuck]);

foreach ([3000, 2000, 1000] as $ago) {
    Db::insert('job_runs', ['job' => 'cleanup', 'trigger_source' => 'cron', 'dry_run' => 0, 'status' => 'failed', 'started_at' => Clock::sql($now - $ago), 'finished_at' => Clock::sql($now - $ago + 2), 'duration_ms' => 2000, 'error_category' => 'network', 'error_message' => 'cURL error 28: timed out']);
}

Settings::flush();
$run = Jobs::run('watchdog', ['source' => 'admin']);
echo json_encode([
    'run' => ['status' => $run['status'], 'verdicts' => $run['result']['verdicts'] ?? null, 'raised' => $run['result']['alerts_raised'] ?? null],
    'provider_id' => $providerId, 'stuck_order_id' => $stuck,
    'alerts' => Db::all("SELECT id, alert_type, severity, status, title FROM business_alerts ORDER BY id"),
], JSON_UNESCAPED_SLASHES), "\n";
