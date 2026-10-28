<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Log;

/**
 * The one place that turns "a service + how many the customer wants" into a priced,
 * provider-ready order snapshot. Every checkout, manual order, retry and recovery
 * path must build its quantity / amount / provider-quantity through this class —
 * never by reading `price_paise` as a quantity, never by rounding an invalid
 * quantity, and never by letting the browser supply the amount, the provider
 * quantity or the provider cost.
 *
 * Pricing models (services.pricing_model):
 *   MODEL_FIXED    one pack of the service's `quantity` units for `price_paise`.
 *                  The customer cannot change how many — unchanged legacy behaviour.
 *   MODEL_PER_UNIT `price_paise` buys `pricing_unit_qty` units; the customer may
 *                  request any positive whole multiple of `pricing_unit_qty`
 *                  (this also covers "buy N packs of a fixed size" — same maths).
 *
 * `services.provider_qty_divisor` converts the customer-facing unit count into
 * whatever unit the provider bills in (almost always 1). A quantity that does not
 * divide evenly is REJECTED, never rounded — the service is misconfigured, not
 * the order.
 */
final class OrderCalculator
{
    public const MODEL_FIXED = 'fixed_package';
    public const MODEL_PER_UNIT = 'per_unit';

    public const MODELS = [self::MODEL_FIXED, self::MODEL_PER_UNIT];

    /** Sanity ceiling on a single order's charge — never a runaway multiply from a huge multiplier. */
    private const MAX_AMOUNT_PAISE = 100_000_000; // ₹10,00,000

    /**
     * @param array<string,mixed> $service authoritative DB row (Catalog::orderable() or equivalent) — never client-supplied
     * @param mixed $requestedUnits raw client input (string|int|null). Ignored for MODEL_FIXED.
     * @return array{quantity:int,amount_paise:int,provider_quantity:int,pricing_model:string,config_version:int,multiplier:int}
     * @throws HttpException 422 on anything invalid or unresolvable — never silently rounds or substitutes
     */
    public static function calculate(array $service, mixed $requestedUnits = null): array
    {
        $model = (string) ($service['pricing_model'] ?? self::MODEL_FIXED);
        if (!in_array($model, self::MODELS, true)) {
            $model = self::MODEL_FIXED;
        }
        $basePrice = (int) $service['price_paise'];
        $baseQty = (int) $service['quantity'];
        $min = (int) $service['min_quantity'];
        $max = (int) $service['max_quantity'];

        if ($model === self::MODEL_PER_UNIT) {
            $unit = (int) ($service['pricing_unit_qty'] ?? 0);
            if ($unit < 1 || $basePrice < 1) {
                self::reject($service, 'service_misconfigured', 'per_unit service has no valid pricing_unit_qty');
            }
            $units = self::parseUnits($requestedUnits, $unit);
            $multiplier = intdiv($units, $unit);
            $amount = $basePrice * $multiplier;
        } else {
            $units = $baseQty;
            $multiplier = 1;
            $amount = $basePrice;
        }

        if ($units < $min || $units > $max) {
            throw new HttpException(422, 'The requested quantity is outside what this service allows (' . $min . '–' . $max . ').', 'invalid_quantity', [
                'fields' => ['quantity' => 'Must be between ' . $min . ' and ' . $max . '.'],
            ]);
        }
        if ($amount < 1 || $amount > self::MAX_AMOUNT_PAISE) {
            throw new HttpException(422, 'The calculated amount is not valid for this order.', 'invalid_amount');
        }

        $divisor = max(1, (int) ($service['provider_qty_divisor'] ?? 1));
        if ($units % $divisor !== 0) {
            self::reject($service, 'provider_unit_mismatch', 'requested units do not divide evenly by provider_qty_divisor', ['units' => $units, 'divisor' => $divisor]);
        }
        $providerQuantity = intdiv($units, $divisor);
        if ($providerQuantity < 1) {
            self::reject($service, 'invalid_provider_quantity', 'computed provider quantity is below 1', ['units' => $units, 'divisor' => $divisor]);
        }

        return [
            'quantity'          => $units,
            'amount_paise'      => $amount,
            'provider_quantity' => $providerQuantity,
            'pricing_model'     => $model,
            'config_version'    => (int) ($service['config_version'] ?? 1),
            'multiplier'        => $multiplier,
        ];
    }

    /** Validate & normalise the raw client-submitted unit count for a per_unit service. Never trusted beyond this. */
    private static function parseUnits(mixed $raw, int $unit): int
    {
        if (is_int($raw)) {
            $n = $raw;
        } elseif (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            $n = (int) $raw;
        } else {
            throw new HttpException(422, 'Enter how many you want to buy — a whole number.', 'invalid_quantity', [
                'fields' => ['quantity' => 'Enter a whole number.'],
            ]);
        }
        if ($n < 1 || $n > 2_000_000_000) {
            throw new HttpException(422, 'Enter a valid, positive whole number of units.', 'invalid_quantity', [
                'fields' => ['quantity' => 'Must be a positive whole number.'],
            ]);
        }
        if ($n % $unit !== 0) {
            throw new HttpException(422, 'The quantity must be a multiple of ' . $unit . '.', 'invalid_quantity', [
                'fields' => ['quantity' => 'Must be a multiple of ' . $unit . '.'],
            ]);
        }
        return $n;
    }

    /** @param array<string,mixed> $service @param array<string,mixed> $context */
    private static function reject(array $service, string $kind, string $reason, array $context = []): never
    {
        Log::error('Order rejected: the provider quantity model could not be resolved (' . $reason . ')', $context + [
            'service_id' => $service['id'] ?? null, 'code' => $service['code'] ?? null, 'kind' => $kind,
        ]);
        throw new HttpException(422, 'This service is not configured correctly for its provider. Please pick another one or contact support.', $kind);
    }
}
