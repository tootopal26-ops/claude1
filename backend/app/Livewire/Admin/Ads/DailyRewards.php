<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Settings\PlatformSettingsService;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Livewire\Admin\Ads\Concerns\EditsSettings;
use App\Models\DailyRewardClaim;
use App\Models\DailyRewardDismissal;
use App\Models\RewardedClaim;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ads & Monetization > Daily login rewards: the popup's rules and text, its claim and
 * verification figures, and the recent claims. Each player can succeed once per day (a
 * unique key per player and day); the amount is drawn on the server within the range.
 */
#[Layout('components.layouts.admin')]
class DailyRewards extends Component
{
    use AdsPage;
    use EditsSettings;
    use WithPagination;

    protected function settingKeys(): array
    {
        return [
            'daily_reward_enabled', 'daily_reward_requires_ad', 'daily_reward_min_deposit',
            'daily_reward_min_coins', 'daily_reward_max_coins', 'daily_reward_title', 'daily_reward_description',
            'daily_reward_starts_on', 'daily_reward_ends_on', 'daily_reward_timezone',
        ];
    }

    public function mount(PlatformSettingsService $settings): void
    {
        $this->loadSettings($settings);
    }

    public function save(PlatformSettingsService $settings): void
    {
        $this->saveSettings($settings, 'Daily login reward settings saved.');
    }

    public function render()
    {
        $timezone = (string) config('minimax.daily_reward_timezone');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $weekAgo = CarbonImmutable::now($timezone)->subDays(6)->toDateString();
        $since = CarbonImmutable::now($timezone)->subDays(6)->startOfDay()->utc();
        $rewardClaims = RewardedClaim::where('purpose', 'daily_reward')->where('created_at', '>=', $since);

        return view('livewire.admin.ads.daily-rewards', [
            'stats' => [
                'credited_today' => DailyRewardClaim::whereDate('reward_day', $today)->where('status', 'credited')->count(),
                'reserved_today' => DailyRewardClaim::whereDate('reward_day', $today)->where('status', 'reserved')->count(),
                'dismissed_today' => DailyRewardDismissal::whereDate('reward_day', $today)->count(),
                'credited_week' => DailyRewardClaim::whereDate('reward_day', '>=', $weekAgo)->where('status', 'credited')->count(),
                'coins_week' => number_format((float) DailyRewardClaim::whereDate('reward_day', '>=', $weekAgo)->where('status', 'credited')->sum('amount'), 2, '.', ''),
                'ads_started_week' => (clone $rewardClaims)->count(),
                'ads_verified_week' => (clone $rewardClaims)->where('status', 'verified')->count(),
                'ads_failed_week' => (clone $rewardClaims)->whereIn('status', ['rejected', 'expired', 'cancelled'])->count(),
            ],
            'claims' => DailyRewardClaim::with('user')->latest('id')->paginate(20),
            'history' => $this->settingsHistory(),
            'today' => $today,
        ]);
    }
}
