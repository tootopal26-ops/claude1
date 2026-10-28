<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Ads\AdsAnalytics;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AdPlacement;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ads & Monetization > Overview: date-filterable figures from the database only. App-reported
 * estimates, verified completions, provider-reported revenue and credited coins are shown
 * as the distinct things they are; with no data, every figure reads zero.
 */
#[Layout('components.layouts.admin')]
class Overview extends Component
{
    use AdsPage;

    #[Url]
    public string $range = 'last_7_days';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $placement = '';

    #[Url]
    public string $network = '';

    #[Url]
    public string $purpose = '';

    #[Url]
    public string $player = '';

    public function render(AdsAnalytics $analytics)
    {
        $range = array_key_exists($this->range, AdsAnalytics::RANGES) ? $this->range : 'last_7_days';
        [$start, $end] = $analytics->range($range, $this->from, $this->to);

        $playerUser = null;
        if (trim($this->player) !== '') {
            $term = trim($this->player);
            $playerUser = ctype_digit($term) ? User::find((int) $term) : User::where('username', $term)->first();
        }

        $filters = [
            'placement' => $this->placement !== '' ? $this->placement : null,
            'network' => $this->network !== '' ? $this->network : null,
            'purpose' => array_key_exists($this->purpose, AdsAnalytics::REWARD_TYPES) ? $this->purpose : null,
            // An unknown player matches nothing rather than everything.
            'user_id' => trim($this->player) !== '' ? ($playerUser?->id ?? -1) : null,
        ];

        $daily = $analytics->daily($start, $end, $filters);

        return view('livewire.admin.ads.overview', [
            'start' => $start->setTimezone($analytics->timezone()),
            'end' => $end->setTimezone($analytics->timezone())->subSecond(),
            'stats' => $analytics->overview($start, $end, $filters),
            'daily' => $daily,
            'dailyMax' => max(1, ...array_map(fn ($day) => max($day['impressions'], $day['completed']), $daily ?: [['impressions' => 0, 'completed' => 0]])),
            'byPlacement' => $analytics->byPlacement($start, $end, $filters),
            'byNetwork' => $analytics->byNetwork($start, $end, $filters),
            'placements' => AdPlacement::orderBy('name')->get(['key', 'name']),
            'networks' => $analytics->networks(),
            'playerUser' => $playerUser,
            'testMode' => (bool) config('minimax.ads_test_mode'),
            'masterEnabled' => (bool) config('minimax.ads_master_enabled'),
        ]);
    }
}
