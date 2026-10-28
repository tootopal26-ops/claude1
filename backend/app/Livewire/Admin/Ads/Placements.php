<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Ads\AdsAnalytics;
use App\Domain\Ads\AdsConfig;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AdPlacement;
use App\Models\AdUnit;
use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ads & Monetization > Placements: each place an ad may appear, switched on or off
 * independently, with its ad unit, caps and (for feeds) how often it is inserted. "All
 * ads" off overrides every placement; an unsupported placement can't be switched on.
 */
#[Layout('components.layouts.admin')]
class Placements extends Component
{
    use AdsPage;

    public const array FEED_INTERVALS = [3, 5, 8, 10];

    /** @var array<int, array{is_enabled: bool, ad_unit_id: string, daily_cap: string, cooldown: string, insert_every: string}> */
    public array $rows = [];

    public function mount(): void
    {
        foreach (AdPlacement::all() as $placement) {
            $this->rows[$placement->id] = [
                'is_enabled' => $placement->is_enabled,
                'ad_unit_id' => (string) $placement->ad_unit_id,
                'daily_cap' => (string) $placement->daily_cap_per_user,
                'cooldown' => (string) $placement->cooldown_seconds,
                'insert_every' => (string) $placement->insert_every,
            ];
        }
    }

    public function save(int $id): void
    {
        $this->ensureCanManage();
        $placement = AdPlacement::findOrFail($id);
        $row = $this->rows[$id] ?? [];
        $field = fn (string $name) => "rows.{$id}.{$name}";
        $this->resetErrorBag();

        $enabled = (bool) ($row['is_enabled'] ?? false);
        $unitId = ($row['ad_unit_id'] ?? '') !== '' ? (int) $row['ad_unit_id'] : null;
        $unit = $unitId ? AdUnit::find($unitId) : null;

        if ($enabled && ! $placement->is_supported) {
            $this->addError($field('is_enabled'), 'This placement isn\'t supported by the ad SDK and can\'t be switched on.');

            return;
        }
        if ($unitId && (! $unit || $unit->format !== $placement->format)) {
            $this->addError($field('ad_unit_id'), 'Choose a '.$placement->format.' ad unit.');

            return;
        }
        if ($enabled && (! $unit || ! $unit->is_active)) {
            $this->addError($field('ad_unit_id'), 'Pick an active ad unit before switching this placement on.');

            return;
        }

        $integer = function (string $name, int $min, int $max) use ($row, $field): ?int {
            $raw = trim((string) ($row[$name] ?? ''));
            if ($raw === '') {
                return null;
            }
            if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < $min || (int) $raw > $max) {
                $this->addError($field($name), "Enter a whole number from {$min} to {$max}, or leave it empty.");

                return -1;
            }

            return (int) $raw;
        };

        $dailyCap = $integer('daily_cap', 1, 500);
        $cooldown = $integer('cooldown', 0, 86400);
        $insertEvery = $placement->format === 'native' && str_contains($placement->key, 'feed') ? $integer('insert_every', 3, 50) : null;
        if (in_array(-1, [$dailyCap, $cooldown, $insertEvery], true)) {
            return;
        }

        $previous = $placement->only(['is_enabled', 'ad_unit_id', 'daily_cap_per_user', 'cooldown_seconds', 'insert_every']);
        $placement->update([
            'is_enabled' => $enabled,
            'ad_unit_id' => $unitId,
            'daily_cap_per_user' => $dailyCap,
            'cooldown_seconds' => $cooldown,
            'insert_every' => $insertEvery,
            'updated_by_staff_id' => $this->actor()->id,
        ]);

        AuditLog::record('ads.placement.updated', $this->actor(), $placement, [
            'key' => $placement->key,
            'previous' => $previous,
            'new' => $placement->only(array_keys($previous)),
        ]);

        session()->flash('status', "\"{$placement->name}\" saved.");
    }

    public function render(AdsAnalytics $analytics, AdsConfig $ads)
    {
        [$start, $end] = $analytics->range('last_7_days');
        $placements = AdPlacement::with('adUnit')->orderBy('id')->get();

        return view('livewire.admin.ads.placements', [
            'placements' => $placements,
            // After every switch: All ads, Rewarded Ads, the placement and its unit.
            'live' => $placements->mapWithKeys(fn (AdPlacement $placement) => [$placement->key => $ads->isPlacementActive($placement->key)]),
            'units' => AdUnit::orderBy('name')->get(),
            'metrics' => collect($analytics->byPlacement($start, $end))->keyBy('key'),
            'masterEnabled' => (bool) config('minimax.ads_master_enabled'),
            'rewardedEnabled' => (bool) config('minimax.ads_rewarded_enabled'),
        ]);
    }
}
