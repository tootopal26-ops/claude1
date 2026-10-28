<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Fulfilment;
use App\Services\Orders;
use App\Support\Clock;

/**
 * Regression suite for the quantity/billing fix: a service's rupee PRICE and its
 * delivered QUANTITY are strictly separate fields end to end — checkout, the order
 * row, and what is actually sent to the SMM provider. See App\Services\OrderCalculator.
 *
 * The scenario this guards against: "customer buys 100 likes for ₹5, provider receives
 * quantity 5" — i.e. the rupee amount leaking into the quantity sent to the provider.
 */

/** service_id + target through the REAL checkout code, with an optional `quantity` (per_unit services). */
function oc_place(int $serviceId, ?int $quantity = null, string $target = 'https://www.instagram.com/p/C1a2B3c4D5e/', string $ip = '203.0.113.50'): array
{
    $input = ['service_id' => $serviceId, 'target' => $target];
    if ($quantity !== null) {
        $input['quantity'] = $quantity;
    }
    $res = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], $ip), $input, ['visitor_id' => null, 'session_id' => null]);
    $payment = Orders::paymentByRef($res['ref']);
    return ['order' => Orders::byId((int) $payment['order_id']), 'payment' => $payment];
}

function oc_pay(array $order, array $payment): array
{
    Db::tx(static function () use ($order, $payment): void {
        Db::update('payments', ['status' => 'paid', 'paid_at' => Clock::sql(), 'paid_amount_paise' => $payment['amount_paise'], 'verified_via' => 'admin'], 'id = ?', [$payment['id']]);
        Db::update('orders', ['payment_status' => 'success', 'paid_at' => Clock::sql(), 'paid_day' => \App\Support\Tz::today()], 'id = ?', [$order['id']]);
        Orders::transition((int) $order['id'], \App\Support\Status::PAID, ['actor' => 'system', 'note' => 'test: paid']);
    });
    return Orders::byId((int) $order['id']);
}

function oc_smm_adds(): array
{
    return array_values(array_filter(T::smm('GET', '/__state')['requests'], fn($r) => $r['action'] === 'add'));
}

// ═════════════════════ the headline scenario: ₹5 for 100 likes ═════════════════════

T::test('fixed_package: a ₹5 order for 100 likes submits provider quantity 100, never 5 (the amount is never read as a quantity)', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid); // ₹5 price, quantity=100 from the seed — unchanged fixed_package default
    $row = Db::one('SELECT price_paise, quantity, pricing_model FROM services WHERE id = ?', [$svcId]);
    T::same(500, (int) $row['price_paise']);
    T::same(100, (int) $row['quantity']);
    T::same('fixed_package', $row['pricing_model']);

    ['order' => $o, 'payment' => $p] = oc_place($svcId);
    T::same(100, (int) $o['quantity'], 'the order keeps 100 units, not the ₹5 price');
    T::same(100, (int) $o['provider_quantity'], 'the provider quantity is also 100 — never derived from the price');
    T::same(500, (int) $o['amount_paise'], 'the customer is charged ₹5');
    T::same(500, (int) $p['amount_paise']);

    oc_pay($o, $p);
    Fulfilment::submit((int) $o['id']);
    $adds = oc_smm_adds();
    T::same(1, count($adds));
    T::same('100', $adds[0]['quantity'], 'the provider literally received quantity=100, not 5');
});

T::test('per_unit: ₹5 per 100 likes, customer buys 300 → charged ₹15, provider quantity 300', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid);
    Db::update('services', ['pricing_model' => 'per_unit', 'pricing_unit_qty' => 100, 'max_quantity' => 100_000], 'id = ?', [$svcId]);

    ['order' => $o, 'payment' => $p] = oc_place($svcId, 300);
    T::same(300, (int) $o['quantity']);
    T::same(300, (int) $o['provider_quantity']);
    T::same(1500, (int) $o['amount_paise'], '3 × ₹5 = ₹15');
    T::same(1500, (int) $p['amount_paise']);

    oc_pay($o, $p);
    Fulfilment::submit((int) $o['id']);
    $adds = oc_smm_adds();
    T::same('300', $adds[0]['quantity']);
});

T::test('per_unit + provider_qty_divisor: the provider bills in packs of 100 even though the customer sees individual units', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid);
    Db::update('services', ['pricing_model' => 'per_unit', 'pricing_unit_qty' => 100, 'provider_qty_divisor' => 100, 'max_quantity' => 100_000], 'id = ?', [$svcId]);

    ['order' => $o, 'payment' => $p] = oc_place($svcId, 300);
    T::same(300, (int) $o['quantity'], 'the CUSTOMER still bought 300 units');
    T::same(3, (int) $o['provider_quantity'], 'the PROVIDER is asked for 3 packs of 100 — never confused with the customer quantity');
    T::same(1500, (int) $o['amount_paise']);

    oc_pay($o, $p);
    Fulfilment::submit((int) $o['id']);
    $adds = oc_smm_adds();
    T::same('3', $adds[0]['quantity']);
});

// ═════════════════════ tampering / invalid input is rejected server-side ═════════════════════

T::test('tampering: extra client-supplied price/amount/provider_quantity fields in the checkout body are simply ignored', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid);
    $res = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], '203.0.113.60'), [
        'service_id' => $svcId, 'target' => 'https://www.instagram.com/p/C1a2B3c4D5e/',
        // none of these exist as checkout inputs — Orders::checkout() only ever reads service_id/target/quantity
        'price' => 1, 'amount_paise' => 1, 'provider_quantity' => 1, 'provider_cost_paise' => 0, 'quantity' => 5,
    ], ['visitor_id' => null, 'session_id' => null]);
    $o = Orders::byId((int) Orders::paymentByRef($res['ref'])['order_id']);
    // fixed_package ignores the posted "quantity" entirely — it is not a per_unit service
    T::same(100, (int) $o['quantity']);
    T::same(500, (int) $o['amount_paise']);
});

T::test('per_unit: a quantity that is not a whole multiple of the priced unit is rejected, not rounded', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid);
    Db::update('services', ['pricing_model' => 'per_unit', 'pricing_unit_qty' => 100, 'max_quantity' => 100_000], 'id = ?', [$svcId]);

    foreach ([150, 0, -100, 100_000_000, 99] as $bad) {
        $e = T::throws(HttpException::class, fn() => oc_place($svcId, $bad), 422);
        T::same('invalid_quantity', $e->errorCode);
    }
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM orders'), 'no order row was left behind by any rejected attempt');
});

T::test('per_unit: a missing or non-numeric quantity is rejected', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid);
    Db::update('services', ['pricing_model' => 'per_unit', 'pricing_unit_qty' => 100, 'max_quantity' => 100_000], 'id = ?', [$svcId]);

    T::throws(HttpException::class, fn() => oc_place($svcId, null), 422);
    foreach (['abc', '1.5', '-1', ''] as $bad) {
        $e = T::throws(HttpException::class, function () use ($svcId, $bad) {
            Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], '203.0.113.61'), ['service_id' => $svcId, 'target' => 'https://www.instagram.com/p/C1a2B3c4D5e/', 'quantity' => $bad], ['visitor_id' => null, 'session_id' => null]);
        }, 422);
        T::same('invalid_quantity', $e->errorCode);
    }
});

T::test('a quantity outside min/max is rejected, and min/max are enforced on top of the unit-multiple rule', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid);
    Db::update('services', ['pricing_model' => 'per_unit', 'pricing_unit_qty' => 100, 'quantity' => 300, 'min_quantity' => 200, 'max_quantity' => 500], 'id = ?', [$svcId]);

    T::throws(HttpException::class, fn() => oc_place($svcId, 100), 422);   // multiple of the unit, but below min_quantity
    T::throws(HttpException::class, fn() => oc_place($svcId, 600), 422);  // multiple of the unit, but above max_quantity
    ['order' => $o] = oc_place($svcId, 300);
    T::same(300, (int) $o['quantity']);
});

T::test('a provider_qty_divisor that does not divide the service quantity evenly is rejected at checkout, not rounded', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid); // quantity = 100 (fixed_package)
    Db::update('services', ['provider_qty_divisor' => 3], 'id = ?', [$svcId]); // 100 % 3 != 0 → the service is misconfigured, not the order
    $e = T::throws(HttpException::class, fn() => oc_place($svcId), 422);
    T::same('provider_unit_mismatch', $e->errorCode);
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM orders'));
});

// ═════════════════════ duplicate / reuse behaviour now considers quantity ═════════════════════

T::test('two live payment sessions for the same service+target but DIFFERENT quantities are never merged into one', function () {
    $gid = pf_setup();
    $pid = T::configureSmm();
    $svcId = T::makeAuto('ig-likes-100', '2001', 80, $pid);
    Db::update('services', ['pricing_model' => 'per_unit', 'pricing_unit_qty' => 100, 'max_quantity' => 100_000], 'id = ?', [$svcId]);
    $vid = Db::insert('visitors', ['anon_id' => bin2hex(random_bytes(16)), 'first_seen_at' => Clock::sql(), 'last_seen_at' => Clock::sql()]);
    $visitor = ['visitor_id' => $vid, 'session_id' => null];

    $target = 'https://www.instagram.com/p/C1a2B3c4D5e/';
    $r1 = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], '203.0.113.70'), ['service_id' => $svcId, 'target' => $target, 'quantity' => 100], $visitor);
    $r2 = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], '203.0.113.70'), ['service_id' => $svcId, 'target' => $target, 'quantity' => 300], $visitor);
    T::true($r1['order_id'] !== $r2['order_id'], 'different quantities must never reuse the same pending order');
    $o1 = Orders::byId((int) Orders::paymentByRef($r1['ref'])['order_id']);
    $o2 = Orders::byId((int) Orders::paymentByRef($r2['ref'])['order_id']);
    T::same(100, (int) $o1['quantity']);
    T::same(300, (int) $o2['quantity']);

    // the SAME quantity for the same service+target+visitor IS reused (no duplicate order/charge)
    $r3 = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], '203.0.113.70'), ['service_id' => $svcId, 'target' => $target, 'quantity' => 300], $visitor);
    T::same($r2['order_id'], $r3['order_id']);
});

// ═════════════════════ manual services are never sent to a provider ═════════════════════

T::test('a MANUAL per_unit order is never submitted to the SMM provider regardless of quantity', function () {
    $gid = pf_setup();
    $svc = Db::one('SELECT id FROM services WHERE code = ?', ['ig-likes-100']);
    Db::update('services', ['pricing_model' => 'per_unit', 'pricing_unit_qty' => 100, 'max_quantity' => 100_000], 'id = ?', [$svc['id']]);
    ['order' => $o, 'payment' => $p] = oc_place((int) $svc['id'], 500);
    T::same('MANUAL', $o['mode']);
    oc_pay($o, $p);
    $res = Fulfilment::submit((int) $o['id']);
    T::same('failed', $res['status']);
    T::contains('not an AUTO order', $res['message']);
    T::same(0, count(T::smm('GET', '/__state')['requests']), 'no provider request was ever made for a MANUAL order');
});
