<?php
// QA helper (local only): make one MANUAL service eligible for adaptive pricing (fulfilment cost set, adaptive_enabled on) and give it a little
// real paid-order history so the Price Experiment Simulator has a real trailing volume to project from.   php tools/qa/pricing-seed.php → JSON
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }

use App\Core\Db;
use App\Support\Clock;
use App\Support\Tz;

$code = 'yt-likes-50k'; // ₹49, a valid price-ending example already
$svc = Db::one('SELECT * FROM services WHERE code = ?', [$code]);
Db::update('services', ['adaptive_enabled' => 1, 'fulfil_cost_paise' => 500, 'pricing_paused' => 0], 'id = ?', [$svc['id']]);

$made = 0;
for ($i = 0; $i < 8; $i++) {
    $day = Tz::addDays(Tz::today(), -$i - 1);
    $publicId = 'SMB-QAP' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    if ((int) Db::val('SELECT COUNT(*) FROM orders WHERE public_id = ?', [$publicId]) > 0) {
        continue;
    }
    Db::insert('orders', [
        'public_id' => $publicId, 'access_token' => bin2hex(random_bytes(16)), 'service_id' => $svc['id'], 'platform_id' => $svc['platform_id'],
        'platform_slug' => 'youtube', 'service_code' => $code, 'service_title' => $svc['title'], 'service_type' => $svc['type'], 'mode' => 'MANUAL', 'quantity' => 10,
        'amount_paise' => (int) $svc['price_paise'], 'target' => '@qa', 'target_url' => 'https://www.youtube.com/@qa', 'status' => 'COMPLETED', 'payment_status' => 'success',
        'created_day' => $day, 'paid_day' => $day, 'paid_at' => Clock::sql(), 'created_at' => Clock::sql(), 'updated_at' => Clock::sql(),
    ]);
    $made++;
}

echo json_encode(['service_id' => (int) $svc['id'], 'code' => $code, 'title' => $svc['title'], 'price_paise' => (int) $svc['price_paise'], 'orders_made' => $made]), "\n";
