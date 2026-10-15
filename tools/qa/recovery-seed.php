<?php
// QA helper (local only): put paid AUTO orders into every recovery state through the real code paths, against the mock gateway and the mock
// SMM panel, and print them as JSON.   php tools/qa/recovery-seed.php
//   r_live     delivered normally (at the provider)          r_waiting  paid, not sent yet               r_retry    provider unreachable → retry scheduled
//   r_failed   the provider refused it (bad service id)      r_unknown  garbled answer → outcome unknown   r_link     unknown, but the provider HAS it (id printed)
//   r_resend   unknown, and the provider has none            r_stale    at the provider, not followed     r_review   marked for review by a person
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }

use App\Core\Db;
use App\Core\Request;
use App\Services\Fulfilment;
use App\Services\Orders;
use App\Services\Recovery;
use App\Services\Settlement;
use App\Support\Clock;

const ADMIN = ['id' => 1, 'username' => 'owner', 'email' => 'owner@example.test', 'role' => 'owner'];
$smm = static function (string $path, array $body = []): array {
    $ch = curl_init('http://127.0.0.1:4020' . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $out = json_decode((string) curl_exec($ch), true);
    unset($ch);
    return is_array($out) ? $out : [];
};
$svc = Db::one("SELECT id, smm_service_id FROM services WHERE code = 'ig-followers-50'");
if (($svc['smm_service_id'] ?? '') === '') { fwrite(STDERR, "ig-followers-50 has no SMM service id: run useMockProviderIds() first\n"); exit(1); }

$n = 0;
$place = static function (string $name) use ($svc, &$n): array {
    $res = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], '198.51.100.' . (100 + (++$n))), ['service_id' => (int) $svc['id'], 'target' => 'https://www.instagram.com/' . $name . '/'], ['visitor_id' => null, 'session_id' => null]);
    $p = Orders::paymentByRef($res['ref']);
    Settlement::settle((int) $p['id'], ['source' => 'webhook', 'amount_paise' => (int) $p['amount_paise'], 'utr' => 'RCQA' . $n . strtoupper(bin2hex(random_bytes(3)))]);
    return Orders::byId((int) $p['order_id']);
};
$out = [];
$keep = static function (string $key, array $o) use (&$out): void {
    $o = Orders::byId((int) $o['id']);
    $out[$key] = ['id' => (int) $o['id'], 'public_id' => $o['public_id'], 'status' => $o['status'], 'target' => $o['target_url'], 'provider_order_id' => $o['provider_order_id']];
};

$o = $place('r_live');         Fulfilment::submit((int) $o['id']);                      $keep('r_live', $o);
$o = $place('r_waiting');                                                              $keep('r_waiting', $o);
$o = $place('r_failed');       Db::update('orders', ['smm_service_id' => '9999'], 'id = ?', [$o['id']]); Fulfilment::submit((int) $o['id']);   $keep('r_failed', $o);
$smm('/__control', ['garbled' => true]);
$o = $place('r_unknown');      Fulfilment::submit((int) $o['id']);                      $keep('r_unknown', $o);
$o = $place('r_link');         Fulfilment::submit((int) $o['id']);                      $keep('r_link', $o);
$o = $place('r_resend');       Fulfilment::submit((int) $o['id']);                      $keep('r_resend', $o);
$smm('/__control', ['garbled' => false]);
// the provider does have r_link's order (what a person finds in the panel)
$ch = curl_init('http://127.0.0.1:4020/api/v2');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['key' => 'smm-key-ok', 'action' => 'add', 'service' => $svc['smm_service_id'], 'link' => 'https://www.instagram.com/r_link/', 'quantity' => 50]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$found = json_decode((string) curl_exec($ch), true);
unset($ch);
$out['r_link']['panel_order_id'] = (string) ($found['order'] ?? '');

$prov = Db::one('SELECT id, api_url FROM smm_providers WHERE is_default = 1 LIMIT 1') ?? Db::one('SELECT id, api_url FROM smm_providers LIMIT 1');
Db::update('smm_providers', ['api_url' => 'http://127.0.0.1:4099/api/v2'], 'id = ?', [$prov['id']]);       // closed port: nothing is ever sent
$o = $place('r_retry');        Fulfilment::submit((int) $o['id']);                      $keep('r_retry', $o);
Db::update('smm_providers', ['api_url' => $prov['api_url']], 'id = ?', [$prov['id']]);

$o = $place('r_stale');        Fulfilment::submit((int) $o['id']);
Db::update('orders', ['submitted_at' => Clock::sql(Clock::now() - 7200), 'last_sync_at' => Clock::sql(Clock::now() - 5400)], 'id = ?', [$o['id']]);                $keep('r_stale', $o);
$o = $place('r_review');       Fulfilment::submit((int) $o['id']);
Db::update('orders', ['last_sync_at' => Clock::sql()], 'id = ?', [$o['id']]);
Recovery::act((int) $o['id'], ['action' => 'mark_review', 'note' => 'Customer asked about progress'], null, ADMIN);                                                    $keep('r_review', $o);
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
