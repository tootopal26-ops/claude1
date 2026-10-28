<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Settings\PlatformSettingsService;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ads & Monetization > Audit log: the general audit log narrowed to this section — ad unit,
 * placement, campaign, revenue and settlement actions, plus changes to the ad-related
 * platform settings (with previous and new values). Read-only.
 */
#[Layout('components.layouts.admin')]
class AdsAuditLog extends Component
{
    use AdsPage;
    use WithPagination;

    public const array SETTING_GROUPS = ['ads', 'ads_earning', 'daily_reward', 'tickets'];

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $settingKeys = collect(self::SETTING_GROUPS)->flatMap(fn (string $group) => array_keys(PlatformSettingsService::catalogFor($group)))->all();

        $logs = AuditLog::query()
            ->with('actor')
            ->where(fn (Builder $query) => $query
                ->where('action', 'like', 'ads.%')
                ->orWhere(fn (Builder $settings) => $settings->where('action', 'platform_settings.updated')->whereIn('metadata->key', $settingKeys)))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->latest('id')
            ->paginate(25);

        return view('livewire.admin.ads.ads-audit-log', ['logs' => $logs]);
    }
}
