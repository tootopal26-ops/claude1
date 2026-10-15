<?php
/**
 * DEV ONLY: point the (dev) database at the local mock gateway + mock SMM panel and optionally add demo data.
 *   php tools/dev-setup.php            configure gateway + SMM
 *   php tools/dev-setup.php demo       …and generate ~60 days of realistic demo orders/visitors
 */
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "Refusing to run: APP_ENV is not 'local'.\n"); exit(1); }

use App\Core\{Crypto, Db};
use App\Support\Clock;

$gw = Db::one('SELECT id FROM payment_gateways ORDER BY id LIMIT 1');
// DEV_GATEWAY_STYLE=headers → the current Alpha UPI API (X-API-Key + X-API-Secret, what tools/local-demo.php shows); default: the older Bearer API
$style = getenv('DEV_GATEWAY_STYLE') === 'headers' ? 'headers' : 'bearer';
Db::update('payment_gateways', [
    'is_active' => 1, 'environment' => 'test', 'config_json' => json_encode(['base_url' => 'http://127.0.0.1:4010', 'api_style' => $style, 'provider_pref' => 'auto']),
    'secrets_enc' => Crypto::encryptJson(['api_key' => 'test-api-key-123', 'webhook_secret' => 'test-webhook-secret-456'] + ($style === 'headers' ? ['api_secret' => 'test-api-secret-789'] : [])),
], 'id = ?', [$gw['id']]);
$gid = (int) $gw['id'];

Db::exec('DELETE FROM smm_providers');
$pid = Db::insert('smm_providers', [
    'name' => 'Mock SMM Panel', 'api_url' => 'http://127.0.0.1:4020/api/v2', 'api_key_enc' => Crypto::encrypt('smm-key-ok'),
    'is_active' => 1, 'is_default' => 1, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql(),
]);
echo "gateway #{$gid} → mock Alpha UPI (webhook URL: " . rtrim((string) App\Core\Env::get('APP_URL', 'http://127.0.0.1:8080'), '/') . "/webhooks/payments/{$gid}); SMM provider #{$pid} → mock panel\n";

if (($argv[1] ?? '') === 'demo') {
    require __DIR__ . '/demo-data.php';
}
