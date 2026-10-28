<?php

namespace App\Livewire\Admin;

use App\Domain\Redeem\RedeemCampaignService;
use App\Models\RedeemCampaign;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class RedeemCodes extends Component
{
    use WithPagination;

    public const DURATIONS = ['1' => '1 hour', '6' => '6 hours', '12' => '12 hours', '24' => '24 hours (default)', '48' => '2 days', '72' => '3 days', '168' => '7 days', 'custom' => 'Custom…'];

    public string $name = '';

    public string $capacity = '';

    public string $reward = '';

    public string $duration = '24';

    public string $customHours = '';

    public bool $isActive = true;

    /** The player must watch one rewarded ad (verified by the ad network) before the code credits. */
    public bool $requiresAd = false;

    public ?int $generatedId = null;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function generate(RedeemCampaignService $campaigns): void
    {
        $staff = $this->actor();

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reward' => ['required', 'integer', 'min:1', 'max:1000000'],
            'duration' => ['required', 'in:'.implode(',', array_keys(self::DURATIONS))],
            'customHours' => [$this->duration === 'custom' ? 'required' : 'nullable', 'integer', 'min:1', 'max:8760'],
            'isActive' => ['boolean'],
            'requiresAd' => ['boolean'],
        ], [], [
            'name' => 'campaign name',
            'capacity' => 'player limit',
            'reward' => 'reward per player',
            'customHours' => 'custom duration',
        ]);

        $campaign = $campaigns->create([
            'name' => trim($this->name),
            'total_capacity' => (int) $this->capacity,
            'reward_coins' => (int) $this->reward,
            'expires_at' => now()->addHours($this->hours()),
            'is_active' => $this->isActive,
            'requires_rewarded_ad' => $this->requiresAd,
        ], $staff);

        $this->generatedId = $campaign->id;
        $this->reset(['name', 'capacity', 'reward', 'customHours']);
        $this->duration = '24';
        $this->isActive = true;
        $this->requiresAd = false;
        $this->resetPage();
    }

    public function toggle(int $campaignId, RedeemCampaignService $campaigns): void
    {
        $campaign = RedeemCampaign::findOrFail($campaignId);
        $campaigns->setActive($campaign, ! $campaign->is_active, $this->actor());
    }

    public function archive(int $campaignId, RedeemCampaignService $campaigns): void
    {
        $campaigns->archive(RedeemCampaign::findOrFail($campaignId), $this->actor());

        if ($this->generatedId === $campaignId) {
            $this->generatedId = null;
        }
    }

    public function unarchive(int $campaignId, RedeemCampaignService $campaigns): void
    {
        $campaigns->unarchive(RedeemCampaign::findOrFail($campaignId), $this->actor());
    }

    public function dismissGenerated(): void
    {
        $this->generatedId = null;
    }

    private function hours(): int
    {
        return $this->duration === 'custom' ? (int) $this->customHours : (int) $this->duration;
    }

    private function actor()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('redeem_codes.manage'), 403);

        return $staff;
    }

    public function render()
    {
        $this->actor();

        $previewHours = $this->duration === 'custom'
            ? (ctype_digit($this->customHours) && (int) $this->customHours >= 1 && (int) $this->customHours <= 8760 ? (int) $this->customHours : null)
            : (int) $this->duration;

        $query = RedeemCampaign::query()
            ->when(trim($this->search) !== '', function ($query) {
                $term = trim($this->search);
                $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('code', strtoupper($term)));
            });

        match ($this->status) {
            'active' => $query->whereNull('archived_at')->where('is_active', true)->where('expires_at', '>', now())->whereColumn('redeemed_count', '<', 'total_capacity'),
            'expired' => $query->whereNull('archived_at')->where('expires_at', '<=', now()),
            'exhausted' => $query->whereNull('archived_at')->whereColumn('redeemed_count', '>=', 'total_capacity'),
            'inactive' => $query->whereNull('archived_at')->where('is_active', false),
            'archived' => $query->whereNotNull('archived_at'),
            default => $query->whereNull('archived_at'),
        };

        return view('livewire.admin.redeem-codes', [
            'campaigns' => $query->orderByDesc('id')->paginate(10),
            'generated' => $this->generatedId ? RedeemCampaign::find($this->generatedId) : null,
            'previewExpiry' => $previewHours ? now()->addHours($previewHours) : null,
            'totals' => [
                'redeemed' => (int) DB::table('redeem_redemptions')->count(),
                'liability' => (int) RedeemCampaign::whereNull('archived_at')->get()->sum(fn (RedeemCampaign $c) => max(0, $c->total_capacity - $c->redeemed_count) * $c->reward_coins),
            ],
        ]);
    }
}
