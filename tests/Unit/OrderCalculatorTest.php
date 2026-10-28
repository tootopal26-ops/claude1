<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Services\OrderCalculator;

/**
 * Pure unit tests for the quantity/price-separation calculator — no database.
 * See tests/Integration/OrderCalculatorTest.php for the end-to-end checkout + provider-submission proof.
 */

function occ_service(array $over = []): array
{
    return $over + [
        'id' => 11, 'code' => 'ig-likes-100', 'price_paise' => 500, 'quantity' => 100,
        'min_quantity' => 1, 'max_quantity' => 100_000_000,
        'pricing_model' => OrderCalculator::MODEL_FIXED, 'pricing_unit_qty' => null,
        'provider_qty_divisor' => 1, 'config_version' => 1,
    ];
}

T::test('fixed_package: the quantity is the service\'s configured quantity, completely independent of the price', function () {
    $r = OrderCalculator::calculate(occ_service(['price_paise' => 500, 'quantity' => 100]));
    T::same(100, $r['quantity']);
    T::same(100, $r['provider_quantity']);
    T::same(500, $r['amount_paise']);
    T::same(1, $r['multiplier']);
    // a client-supplied "quantity" is ignored outright for fixed_package
    $r2 = OrderCalculator::calculate(occ_service(['price_paise' => 500, 'quantity' => 100]), 5);
    T::same(100, $r2['quantity'], 'passing 5 (the price in rupees) as the requested unit count changes nothing');
});

T::test('per_unit: ₹5 buys 100 — 300 requested → ₹15, provider quantity 300', function () {
    $svc = occ_service(['pricing_model' => OrderCalculator::MODEL_PER_UNIT, 'pricing_unit_qty' => 100, 'max_quantity' => 100_000]);
    $r = OrderCalculator::calculate($svc, 300);
    T::same(300, $r['quantity']);
    T::same(1500, $r['amount_paise']);
    T::same(300, $r['provider_quantity']);
    T::same(3, $r['multiplier']);
});

T::test('per_unit: requesting exactly one unit-step is the base price', function () {
    $svc = occ_service(['pricing_model' => OrderCalculator::MODEL_PER_UNIT, 'pricing_unit_qty' => 100, 'max_quantity' => 100_000]);
    $r = OrderCalculator::calculate($svc, 100);
    T::same(100, $r['quantity']);
    T::same(500, $r['amount_paise']);
});

T::test('per_unit: a non-multiple quantity is rejected, never rounded up or down', function () {
    $svc = occ_service(['pricing_model' => OrderCalculator::MODEL_PER_UNIT, 'pricing_unit_qty' => 100, 'max_quantity' => 100_000]);
    foreach ([1, 50, 150, 299, 301] as $bad) {
        $e = T::throws(HttpException::class, fn() => OrderCalculator::calculate($svc, $bad), 422);
        T::same('invalid_quantity', $e->errorCode);
    }
});

T::test('per_unit: zero, negative, absurdly large and non-numeric quantities are all rejected', function () {
    $svc = occ_service(['pricing_model' => OrderCalculator::MODEL_PER_UNIT, 'pricing_unit_qty' => 100, 'max_quantity' => 100_000]);
    foreach ([0, -100, '-100', 'abc', '1e9', '100.0', null, '', 3_000_000_000] as $bad) {
        T::throws(HttpException::class, fn() => OrderCalculator::calculate($svc, $bad), 422);
    }
    // a plain int and a clean digit-string are both accepted
    T::same(100, OrderCalculator::calculate($svc, 100)['quantity']);
    T::same(100, OrderCalculator::calculate($svc, '100')['quantity']);
});

T::test('min/max quantity bounds apply on top of the unit-multiple rule', function () {
    $svc = occ_service(['pricing_model' => OrderCalculator::MODEL_PER_UNIT, 'pricing_unit_qty' => 100, 'min_quantity' => 200, 'max_quantity' => 400]);
    T::throws(HttpException::class, fn() => OrderCalculator::calculate($svc, 100), 422); // below min
    T::throws(HttpException::class, fn() => OrderCalculator::calculate($svc, 500), 422); // above max
    T::same(300, OrderCalculator::calculate($svc, 300)['quantity']);
});

T::test('provider_qty_divisor converts the customer quantity into the provider\'s billing unit', function () {
    $svc = occ_service(['pricing_model' => OrderCalculator::MODEL_PER_UNIT, 'pricing_unit_qty' => 100, 'max_quantity' => 100_000, 'provider_qty_divisor' => 100]);
    $r = OrderCalculator::calculate($svc, 500);
    T::same(500, $r['quantity']);
    T::same(5, $r['provider_quantity'], '500 customer units ÷ 100 per provider pack = 5 packs');
});

T::test('a quantity that does not divide evenly by provider_qty_divisor is rejected as a misconfiguration, never rounded', function () {
    $svc = occ_service(['quantity' => 100, 'provider_qty_divisor' => 3]); // fixed_package, 100 % 3 != 0
    $e = T::throws(HttpException::class, fn() => OrderCalculator::calculate($svc), 422);
    T::same('provider_unit_mismatch', $e->errorCode);
});

T::test('a per_unit service with no configured pricing_unit_qty is refused rather than guessing', function () {
    $svc = occ_service(['pricing_model' => OrderCalculator::MODEL_PER_UNIT, 'pricing_unit_qty' => null]);
    $e = T::throws(HttpException::class, fn() => OrderCalculator::calculate($svc, 100), 422);
    T::same('service_misconfigured', $e->errorCode);
});

T::test('the computed amount has a sane ceiling — a huge multiplier cannot produce a runaway charge', function () {
    $svc = occ_service(['pricing_model' => OrderCalculator::MODEL_PER_UNIT, 'pricing_unit_qty' => 100, 'price_paise' => 500, 'max_quantity' => 2_000_000_000]);
    T::throws(HttpException::class, fn() => OrderCalculator::calculate($svc, 1_000_000_000));
});

T::test('an unknown pricing_model string falls back to fixed_package rather than failing open', function () {
    $svc = occ_service(['pricing_model' => 'something-unexpected', 'quantity' => 100, 'price_paise' => 500]);
    $r = OrderCalculator::calculate($svc, 9999);
    T::same('fixed_package', $r['pricing_model']);
    T::same(100, $r['quantity'], 'the bogus requested quantity is ignored, same as a real fixed_package service');
});
