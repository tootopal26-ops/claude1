<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Ads\AdsAnalytics;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AdSettlementAllocation;
use App\Models\RewardedClaim;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ads & Monetization > User ad activity: one player's Ads Earning switch, eligibility,
 * ads and rewards, with their rewarded-ad history. Only pseudonymous SDK data is stored —
 * no device fingerprints — and the page is limited to staff with ads.view.
 */
#[Layout('components.layouts.admin')]
class UserAdActivity extends Component
{
    use AdsPage;
    use WithPagination;

    #[Url]
    public string $player = '';

    #[Url]
    public string $range = 'last_30_days';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updating(string $name): void
    {
        if (in_array($name, ['player', 'range', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render(AdsAnalytics $analytics)
    {
        $term = trim($this->player);
        $user = $term === '' ? null : (ctype_digit($term) ? User::find((int) $term) : User::where('username', $term)->first());
        $matches = $term !== '' && $user === null
            ? User::where('username', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')->orderBy('username')->limit(8)->get(['id', 'username'])
            : collect();

        [$start, $end] = $analytics->range(array_key_exists($this->range, AdsAnalytics::RANGES) ? $this->range : 'last_30_days', $this->from, $this->to);

        return view('livewire.admin.ads.user-ad-activity', [
            'user' => $user,
            'matches' => $matches,
            'stats' => $user ? $analytics->forUser($user, $start, $end) : null,
            'claims' => $user ? RewardedClaim::where('user_id', $user->id)->where('created_at', '>=', $start)->where('created_at', '<', $end)->latest('id')->paginate(20) : null,
            'allocations' => $user ? AdSettlementAllocation::with('batch')->where('user_id', $user->id)->latest('id')->limit(10)->get() : collect(),
        ]);
    }
}
