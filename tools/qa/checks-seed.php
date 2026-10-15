<?php
// QA helper (local only): create real checkouts against the mock gateway so the "Payment checks" screen has payments to show.
//   php tools/qa/checks-seed.php name1 name2 …     → JSON list of { name, public_id, gateway_order_id, amount_paise, payment_id, order_id }
// Each name becomes the Instagram account link of a ₹5 "50 Followers" order, placed through the real checkout code (so the gateway
// really has the order); the browser suite then pays, mis-pays or leaves them with signed webhooks through the mock gateway.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!App\Core\Env::isLocal()) { fwrite(STDERR, "local only\n"); exit(1); }

use App\Core\Db;
use App\Core\Request;
use App\Services\Orders;

$svc = Db::one("SELECT id FROM services WHERE code = 'ig-followers-50'");
$out = [];
foreach (array_slice($argv, 1) as $i => $name) {
    if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $name)) {
        fwrite(STDERR, "bad name {$name}\n");
        exit(1);
    }
    $ip = '198.51.100.' . (20 + $i);
    $res = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], $ip), ['service_id' => (int) $svc['id'], 'target' => 'https://www.instagram.com/' . $name . '/'], ['visitor_id' => null, 'session_id' => null]);
    $p = Orders::paymentByRef($res['ref']);
    $o = Orders::byId((int) $p['order_id']);
    $out[] = ['name' => $name, 'public_id' => $o['public_id'], 'gateway_order_id' => $p['gateway_order_id'], 'amount_paise' => (int) $p['amount_paise'], 'payment_id' => (int) $p['id'], 'order_id' => (int) $o['id']];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
