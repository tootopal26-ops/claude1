<?php

namespace App\Domain\Sections;

use App\Models\AuditLog;
use App\Models\SectionUnlockPrice;
use App\Models\StaffUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Admin changes to a section's unlock price. The key must be in the registry (it is
 * never taken on trust from the request) and every real change is audit-logged with
 * the before and after. Saving never touches what players already bought: their
 * entitlements stay, whatever the price or switch becomes.
 */
class SectionPricingService
{
    public const MAX_PRICE = 1000000;

    public function __construct(private readonly SectionRegistry $registry) {}

    /**
     * @return bool whether anything changed (false for a save that matches what is stored)
     */
    public function save(StaffUser $actor, string $key, int $priceCoins, bool $enabled): bool
    {
        if (! $this->registry->has($key)) {
            throw new \InvalidArgumentException("Unknown section [{$key}].");
        }

        if ($priceCoins < 1 || $priceCoins > self::MAX_PRICE) {
            throw new \InvalidArgumentException('The price must be between 1 and '.self::MAX_PRICE.' coins.');
        }

        try {
            return DB::transaction(fn () => $this->apply($actor, $key, $priceCoins, $enabled));
        } catch (UniqueConstraintViolationException) {
            // Two admins priced the same new section at once; the row exists now, so update it.
            return DB::transaction(fn () => $this->apply($actor, $key, $priceCoins, $enabled));
        }
    }

    private function apply(StaffUser $actor, string $key, int $priceCoins, bool $enabled): bool
    {
        $row = SectionUnlockPrice::query()->where('section_key', $key)->lockForUpdate()->first();

        $before = ['price' => $row?->price_coins, 'enabled' => (bool) $row?->is_enabled];

        if ($row !== null && $row->price_coins === $priceCoins && $row->is_enabled === $enabled) {
            return false;
        }

        $row ??= new SectionUnlockPrice(['section_key' => $key]);
        $row->fill(['price_coins' => $priceCoins, 'is_enabled' => $enabled, 'updated_by_staff_id' => $actor->id])->save();

        AuditLog::record('section_unlock.updated', $actor, $row, [
            'section' => $key,
            'old_price' => $before['price'],
            'new_price' => $priceCoins,
            'old_enabled' => $before['enabled'],
            'new_enabled' => $enabled,
        ]);

        return true;
    }
}
