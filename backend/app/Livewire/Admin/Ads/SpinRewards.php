<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Settings\PlatformSettingsService;
use App\Domain\Tokens\TokenExchangeService;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Livewire\Admin\Ads\Concerns\EditsSettings;
use App\Models\RewardedClaim;
use App\Models\SpinAttempt;
use App\Models\TokenExchangeTransaction;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ads & Monetization > Spin & Token Rewards: whether the wheel is running, its odds (which
 * must always sum to exactly 100%, enforced server-side), the fixed exchange tiers, and
 * recent spin / exchange activity. Every figure here is read, never trusted from the app —
 * SpinService draws the winning number and TokenExchangeService runs each exchange, both
 * only from a verified rewarded-ad callback (RewardedClaimService).
 */
#[Layout('components.layouts.admin')]
class SpinRewards extends Component
{
    use AdsPage;
    use EditsSettings;
    use WithPagination;

    protected function settingKeys(): array
    {
        return ['spin_enabled', 'spin_prob_1', 'spin_prob_2', 'spin_prob_3', 'spin_prob_4', 'spin_prob_5'];
    }

    public function mount(PlatformSettingsService $settings): void
    {
        $this->loadSettings($settings);
    }

    public function save(PlatformSettingsService $settings): void
    {
        $this->saveSettings($settings, 'Spin wheel settings saved.');
    }

    public function render(TokenExchangeService $exchange)
    {
        $timezone = (string) config('minimax.daily_reward_timezone', 'Asia/Kolkata');
        $today = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $weekAgo = CarbonImmutable::now($timezone)->subDays(6)->startOfDay()->utc();

        $spinsToday = SpinAttempt::where('created_at', '>=', $today);
        $spinsWeek = SpinAttempt::where('created_at', '>=', $weekAgo);
        $exchangesWeek = TokenExchangeTransaction::where('created_at', '>=', $weekAgo)->where('status', 'credited');
        $spinClaims = RewardedClaim::where('purpose', 'spin')->where('created_at', '>=', $weekAgo);

        return view('livewire.admin.ads.spin-rewards', [
            'stats' => [
                'spins_today' => (clone $spinsToday)->count(),
                'tokens_awarded_today' => (clone $spinsToday)->sum('tokens_awarded'),
                'spins_week' => (clone $spinsWeek)->count(),
                'tokens_awarded_week' => (clone $spinsWeek)->sum('tokens_awarded'),
                'breakdown_week' => (clone $spinsWeek)->selectRaw('winning_number, count(*) as c')->groupBy('winning_number')->pluck('c', 'winning_number'),
                'ads_started_week' => (clone $spinClaims)->count(),
                'ads_verified_week' => (clone $spinClaims)->where('status', 'verified')->count(),
                'exchanges_week' => (clone $exchangesWeek)->count(),
                'coins_exchanged_week' => number_format((float) (clone $exchangesWeek)->sum('coins_received'), 2, '.', ''),
                'tokens_exchanged_week' => (clone $exchangesWeek)->sum('tokens_required'),
            ],
            'tiers' => $exchange->tiers(),
            'spins' => SpinAttempt::with('user')->latest('id')->paginate(20, pageName: 'spins'),
            'exchanges' => TokenExchangeTransaction::with('user')->latest('id')->paginate(20, pageName: 'exchanges'),
            'history' => $this->settingsHistory(),
            'today' => CarbonImmutable::now($timezone)->toDateString(),
        ]);
    }
}
