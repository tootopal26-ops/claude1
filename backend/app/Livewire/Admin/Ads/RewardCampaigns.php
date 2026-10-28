<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Ads\AdsConfig;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AdRewardCampaign;
use App\Models\AuditLog;
use App\Models\RewardedClaim;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ads & Monetization > Reward campaigns (Model B): a stated number of coins for one complete
 * rewarded ad on the "Reward campaigns" placement, credited only after the network verifies
 * the ad, capped per player per day and optionally by a total budget. A separate placement
 * and ledger type from Ads Earning, so one ad can never earn both.
 */
#[Layout('components.layouts.admin')]
class RewardCampaigns extends Component
{
    use AdsPage;

    public ?int $editingId = null;

    public string $name = '';

    public string $rewardCoins = '';

    public string $dailyLimit = '1';

    public string $budget = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public bool $isActive = false;

    public function edit(int $id): void
    {
        $this->ensureCanManage();
        $campaign = AdRewardCampaign::findOrFail($id);
        $this->editingId = $campaign->id;
        $this->name = $campaign->name;
        $this->rewardCoins = (string) $campaign->reward_coins;
        $this->dailyLimit = (string) $campaign->daily_limit_per_user;
        $this->budget = (string) $campaign->budget_coins;
        $this->startsAt = $campaign->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->endsAt = $campaign->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->isActive = $campaign->is_active;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'rewardCoins', 'dailyLimit', 'budget', 'startsAt', 'endsAt', 'isActive']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->ensureCanManage();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'rewardCoins' => ['required', 'numeric', 'min:0.01', 'max:10000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'dailyLimit' => ['required', 'integer', 'min:1', 'max:50'],
            'budget' => ['nullable', 'numeric', 'min:0.01', 'max:100000000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'isActive' => ['boolean'],
        ], [], ['rewardCoins' => 'reward', 'dailyLimit' => 'daily limit', 'startsAt' => 'start', 'endsAt' => 'end']);

        $attributes = [
            'name' => trim($validated['name']),
            'reward_coins' => $validated['rewardCoins'],
            'daily_limit_per_user' => (int) $validated['dailyLimit'],
            'budget_coins' => $validated['budget'] !== '' && $validated['budget'] !== null ? $validated['budget'] : null,
            'starts_at' => $validated['startsAt'] ?: null,
            'ends_at' => $validated['endsAt'] ?: null,
            'is_active' => $validated['isActive'],
        ];

        if ($this->editingId) {
            $campaign = AdRewardCampaign::findOrFail($this->editingId);
            $previous = $campaign->only(array_keys($attributes));
            $campaign->update($attributes);
            AuditLog::record('ads.campaign.updated', $this->actor(), $campaign, ['previous' => $previous, 'new' => $campaign->only(array_keys($attributes))]);
        } else {
            $campaign = AdRewardCampaign::create([...$attributes, 'created_by_staff_id' => $this->actor()->id]);
            AuditLog::record('ads.campaign.created', $this->actor(), $campaign, ['new' => $campaign->only(array_keys($attributes))]);
        }

        session()->flash('status', "Campaign \"{$campaign->name}\" saved.");
        $this->cancel();
    }

    public function toggle(int $id): void
    {
        $this->ensureCanManage();
        $campaign = AdRewardCampaign::findOrFail($id);
        $campaign->update(['is_active' => ! $campaign->is_active]);
        AuditLog::record($campaign->is_active ? 'ads.campaign.activated' : 'ads.campaign.deactivated', $this->actor(), $campaign, ['name' => $campaign->name]);
    }

    public function render()
    {
        $campaigns = AdRewardCampaign::latest('id')->get();
        $claims = RewardedClaim::query()
            ->where('purpose', 'campaign')
            ->whereIn('ad_reward_campaign_id', $campaigns->pluck('id'))
            ->selectRaw('ad_reward_campaign_id, COUNT(*) as started, SUM(CASE WHEN wallet_ledger_entry_id IS NOT NULL THEN 1 ELSE 0 END) as credited')
            ->groupBy('ad_reward_campaign_id')
            ->get()
            ->keyBy('ad_reward_campaign_id');

        return view('livewire.admin.ads.reward-campaigns', [
            'campaigns' => $campaigns,
            'claims' => $claims,
            'placementLive' => app(AdsConfig::class)->isPurposeActive('campaign'),
        ]);
    }
}
