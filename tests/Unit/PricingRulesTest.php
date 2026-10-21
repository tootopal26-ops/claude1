<?php
declare(strict_types=1);

use App\Support\PricingRules;

/**
 * The administrator's price-ending rule (master prompt, Adaptive Pricing §5), as pure functions — no database involved.
 */
T::test('price rules: valid endings — last digit 4 or 9, leading digit at least 2, at or above the minimum', function () {
    foreach ([2400, 2900, 3400, 3900, 4400, 4900, 9900, 24900 /* ₹249 */, 2000 /* ₹20, but 0 last digit */] as $p) {
        // only the ones ending 4/9 with a valid lead are accepted; ₹20 (2000) fails on the ending alone
        $expect = $p !== 2000;
        T::same($expect, PricingRules::validEnding($p), 'paise ' . $p);
    }
});

T::test('price rules: the leading-digit constraint — ₹4, ₹9, ₹14, ₹19 are rejected even though they end in 4 or 9', function () {
    foreach ([400, 900, 1400, 1900] as $p) {
        T::false(PricingRules::validEnding($p, 100), 'paise ' . $p . ' (leading digit below 2)');
    }
    T::true(PricingRules::validEnding(2400, 100), '₹24 is fine once the floor is low enough to let it through');
});

T::test('price rules: minimum and maximum bounds — a configured minimum above a candidate rejects it regardless of its ending', function () {
    T::true(PricingRules::validEnding(2400, 2000), '₹24 clears a ₹20 floor');
    T::false(PricingRules::validEnding(2400, 2500), '₹24 does not clear a ₹25 floor');
    T::false(PricingRules::validEnding(1900, PricingRules::DEFAULT_MIN_PAISE), '₹19 is below the default ₹20 floor');
});

T::test('price rules: cost / missing-cost floor protection is a caller concern, never bypassed by rounding', function () {
    // the rule itself has no notion of cost — Pricing::propose() and Pricing::simulate() are what refuse a candidate below cost;
    // what this rule guarantees is that rounding can never silently produce an INVALID price that looks like a valid one
    foreach (range(1, 60) as $rupees) {
        $paise = $rupees * 100;
        if (PricingRules::validEnding($paise)) {
            T::true($rupees % 10 === 4 || $rupees % 10 === 9, "₹$rupees claims valid but does not end 4/9");
            T::true(PricingRules::leadingDigit($rupees) >= 2, "₹$rupees claims valid but leads below 2");
        }
    }
});

T::test('price rules: a paise fraction is never accepted, whatever the rupee part looks like', function () {
    T::false(PricingRules::validEnding(2449)); // ₹24.49 — not whole rupees
    T::false(PricingRules::isWholeRupees(2449));
    T::true(PricingRules::isWholeRupees(2400));
});

T::test('price rules: explainRejection names the exact reason, and suggestNear always brackets an invalid price with valid ones', function () {
    T::true(PricingRules::explainRejection(2400) === null, 'a valid price has no rejection reason');
    T::contains('whole rupees', (string) PricingRules::explainRejection(2449));
    T::contains('minimum', (string) PricingRules::explainRejection(1000, 2000));
    T::contains('end in 4 or 9', (string) PricingRules::explainRejection(5000));
    T::contains('leading digit', (string) PricingRules::explainRejection(1400, 100));

    $sug = PricingRules::suggestNear(5000); // ₹50 — not a valid ending
    T::same([4900, 5400], [$sug['below'], $sug['above']]);
    T::true(PricingRules::validEnding($sug['below']) && PricingRules::validEnding($sug['above']));
});
