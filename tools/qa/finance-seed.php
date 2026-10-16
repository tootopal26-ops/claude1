<?php
// QA helper (local only): add a few PAID orders to the dev database through the real checkout + settlement code (mock gateway), so the finance suite has
// orders of today whose costs it can record, correct and reconcile.   php tools/qa/finance-seed.php   → JSON
//   f_manual_a / f_manual_b   a MANUAL service (Instagram 100K followers, ₹99): paid, no fulfilment cost stated → "incomplete" until a cost is recorded
//   f_auto                    an AUTO service (Instagram 100 likes): paid, not sent to the provider yet → the provider's charge is not known
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }

use App\Core\Db;
use App\Core\Request;
use App\Services\Orders;
use App\Services\Settlement;

$n = 0;
$place = static function (string $code, string $target) use (&$n): array {
    $svc = Db::one('SELECT id FROM services WHERE code = ?', [$code]) ?? throw new RuntimeException("no service $code");
    $res = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], '198.51.100.' . (150 + (++$n))), ['service_id' => (int) $svc['id'], 'target' => $target], ['visitor_id' => null, 'session_id' => null]);
    $p = Orders::paymentByRef($res['ref']);
    Settlement::settle((int) $p['id'], ['source' => 'webhook', 'amount_paise' => (int) $p['amount_paise'], 'utr' => 'FNQA' . $n . strtoupper(bin2hex(random_bytes(3)))]);
    $o = Orders::byId((int) $p['order_id']);
    return ['id' => (int) $o['id'], 'public_id' => $o['public_id'], 'mode' => $o['mode'], 'amount_paise' => (int) $o['amount_paise'], 'service' => $code];
};
echo json_encode([
    'f_manual_a' => $place('ig-followers-100k', 'https://www.instagram.com/f_manual_a/'),
    'f_manual_b' => $place('ig-followers-100k', 'https://www.instagram.com/f_manual_b/'),
    'f_auto' => $place('ig-likes-100', 'https://www.instagram.com/p/FnQaAutoPost1/'),
], JSON_UNESCAPED_SLASHES), "\n";
