<?php

namespace App\Domain\Sections;

use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\SectionUnlock;
use App\Models\SectionUnlockPrice;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sells a section to a player: one transaction that locks the player's wallet,
 * re-reads the current price, charges it through the ledger service and records
 * the entitlement — all of it or none of it.
 *
 * - The wallet row is locked first, so two requests from one player are serialized:
 *   the second sees the entitlement (or the existing ledger rows) and charges nothing.
 * - (player, section) is unique in the database and the ledger charge carries the
 *   idempotency key `section_unlock:{key}:{player}`, so a duplicate purchase cannot
 *   exist even if both application checks were bypassed.
 * - The price is always the stored one. A client that confirmed a different price
 *   than the current one is told so (`price_changed`) and is not charged.
 * - A failed charge rolls the whole transaction back: no entitlement without payment.
 */
class SectionUnlockService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly SectionRegistry $registry,
    ) {}

    /**
     * Every registered section with this player's view of it.
     *
     * @return list<array<string, mixed>>
     */
    public function sectionsFor(User $user): array
    {
        $prices = SectionUnlockPrice::query()->whereIn('section_key', $this->registry->keys())->get()->keyBy('section_key');
        $owned = SectionUnlock::query()->where('user_id', $user->id)->get()->keyBy('section_key');

        return collect($this->registry->all())
            ->map(fn (array $meta, string $key) => $this->describe($key, $meta, $prices->get($key), $owned->get($key)))
            ->values()
            ->all();
    }

    public function statusFor(User $user, string $key): ?array
    {
        $meta = $this->registry->find($key);

        if ($meta === null) {
            return null;
        }

        return $this->describe(
            $key,
            $meta,
            SectionUnlockPrice::where('section_key', $key)->first(),
            SectionUnlock::where('user_id', $user->id)->where('section_key', $key)->first(),
        );
    }

    /** Whether this player may use the section right now: free, or already bought. */
    public function isAccessible(User $user, string $key): bool
    {
        if (! $this->registry->has($key)) {
            return true;
        }

        $price = SectionUnlockPrice::where('section_key', $key)->first();

        if ($price === null || ! $price->is_enabled) {
            return true;
        }

        return SectionUnlock::where('user_id', $user->id)->where('section_key', $key)->exists();
    }

    public function unlock(User $user, string $key, ?int $expectedPrice = null): SectionUnlockOutcome
    {
        if (! $this->registry->has($key)) {
            return new SectionUnlockOutcome('unknown_section', $key);
        }

        if ($user->is_banned) {
            return new SectionUnlockOutcome('ineligible', $key);
        }

        return DB::transaction(function () use ($user, $key, $expectedPrice) {
            // Serializes this player's purchases (and every other wallet operation of theirs).
            $wallet = Wallet::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);

            if (SectionUnlock::where('user_id', $user->id)->where('section_key', $key)->exists()) {
                return new SectionUnlockOutcome('already_unlocked', $key);
            }

            $price = SectionUnlockPrice::where('section_key', $key)->first();

            if ($price === null || ! $price->is_enabled || $price->price_coins < 1) {
                return new SectionUnlockOutcome('not_for_sale', $key);
            }

            if ($expectedPrice !== null && $expectedPrice !== $price->price_coins) {
                return new SectionUnlockOutcome('price_changed', $key, $price->price_coins);
            }

            $amount = number_format($price->price_coins, 2, '.', '');
            $title = $this->registry->find($key)['title'];

            try {
                $split = $this->wallet->debitForPurchase(
                    $user,
                    $amount,
                    'section_unlock',
                    "section_unlock:{$key}:{$user->id}",
                    $price,
                    "Unlocked {$title}",
                );
            } catch (InsufficientWalletBalanceException) {
                return new SectionUnlockOutcome('insufficient_balance', $key, $price->price_coins, '0.00', $wallet->fresh()->totalBalance());
            }

            try {
                SectionUnlock::create([
                    'user_id' => $user->id,
                    'section_key' => $key,
                    'price_paid' => $price->price_coins,
                    'wallet_ledger_entry_id' => $split['entries'][0]->id ?? null,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost a race the wallet lock should have prevented; the ledger key kept the charge single.
                return new SectionUnlockOutcome('already_unlocked', $key);
            }

            return new SectionUnlockOutcome('success', $key, $price->price_coins, $amount);
        });
    }

    /**
     * @param  array{title: string, description: string, icon: string}  $meta
     * @return array<string, mixed>
     */
    private function describe(string $key, array $meta, ?SectionUnlockPrice $price, ?SectionUnlock $owned): array
    {
        $paid = $price !== null && $price->is_enabled && $price->price_coins >= 1;

        return [
            'key' => $key,
            'title' => $meta['title'],
            'description' => $meta['description'],
            'icon' => $meta['icon'],
            'paid' => $paid,
            'price' => $paid ? $price->price_coins : null,
            'unlocked' => ! $paid || $owned !== null,
            'unlocked_at' => $owned?->created_at?->toIso8601String(),
        ];
    }
}
