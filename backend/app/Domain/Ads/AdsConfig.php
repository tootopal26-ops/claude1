<?php

namespace App\Domain\Ads;

use App\Models\AdPlacement;
use Illuminate\Support\Collection;

/**
 * Whether an ad may run, decided in one place, top down:
 *  1. "All ads" (ads_master_enabled) — off means nothing ad-related runs anywhere;
 *  2. "Rewarded ads" (ads_rewarded_enabled) — off means no new rewarded opportunity opens;
 *  3. the placement itself — enabled by Admin, supported by the SDK, and pointing at an
 *     active ad unit.
 * Turning anything off only stops new opportunities; it never touches past rewards.
 */
class AdsConfig
{
    /** Which placement each kind of rewarded claim uses. */
    public const array PURPOSE_PLACEMENTS = [
        'tournament_ticket' => 'tournament_ticket',
        'daily_reward' => 'daily_login',
        'ads_earning' => 'ads_earning',
        'redeem_code' => 'redeem_verification',
        'campaign' => 'rewarded_general',
    ];

    /** @var Collection<string, AdPlacement>|null */
    private ?Collection $placements = null;

    public function masterEnabled(): bool
    {
        return (bool) config('minimax.ads_master_enabled');
    }

    public function rewardedEnabled(): bool
    {
        return $this->masterEnabled() && (bool) config('minimax.ads_rewarded_enabled');
    }

    public function placement(string $key): ?AdPlacement
    {
        return $this->placements()->get($key);
    }

    public function isPlacementActive(string $key): bool
    {
        $placement = $this->placement($key);

        if ($placement === null || ! $this->masterEnabled() || ! $placement->is_supported || ! $placement->is_enabled) {
            return false;
        }

        if ($placement->format === 'rewarded' && ! $this->rewardedEnabled()) {
            return false;
        }

        return $placement->adUnit !== null && $placement->adUnit->is_active;
    }

    public function isPurposeActive(string $purpose): bool
    {
        return isset(self::PURPOSE_PLACEMENTS[$purpose]) && $this->isPlacementActive(self::PURPOSE_PLACEMENTS[$purpose]);
    }

    /**
     * What the app needs to run ads: only public values (the LevelPlay app key and ad unit
     * IDs ship inside every app anyway) and only for placements that are actually active.
     *
     * @return array<string, mixed>
     */
    public function clientConfig(): array
    {
        $active = $this->placements()->filter(fn (AdPlacement $placement) => $this->isPlacementActive($placement->key));

        return [
            'enabled' => $this->masterEnabled() && config('minimax.levelplay_app_key') !== null && $active->isNotEmpty(),
            'rewarded_enabled' => $this->rewardedEnabled(),
            'test_mode' => (bool) config('minimax.ads_test_mode'),
            'levelplay_app_key' => $this->masterEnabled() ? config('minimax.levelplay_app_key') : null,
            'app_open' => [
                'cooldown_minutes' => (int) config('minimax.ads_app_open_cooldown_minutes'),
                'session_cap' => (int) config('minimax.ads_app_open_session_cap'),
            ],
            'placements' => $active->map(fn (AdPlacement $placement) => [
                'key' => $placement->key,
                'format' => $placement->format,
                'ad_unit_id' => $placement->adUnit->levelplay_ad_unit_id,
                'daily_cap' => $placement->daily_cap_per_user,
                'cooldown_seconds' => $placement->cooldown_seconds,
                'insert_every' => $placement->insert_every,
            ])->values()->all(),
        ];
    }

    /**
     * @return Collection<string, AdPlacement>
     */
    private function placements(): Collection
    {
        return $this->placements ??= AdPlacement::query()->with('adUnit')->get()->keyBy('key');
    }
}
