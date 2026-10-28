<?php

namespace App\Livewire\Admin\Ads;

use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AdCallbackLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ads & Monetization > Verification logs: every server-to-server reward callback the ad
 * network sent, whether its signature was valid, and what happened (verified, duplicate,
 * replayed event, expired, unknown claim, bad signature…). Secrets are never stored.
 */
#[Layout('components.layouts.admin')]
class VerificationLogs extends Component
{
    use AdsPage;
    use WithPagination;

    #[Url]
    public string $outcome = '';

    #[Url]
    public string $signature = '';

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
        $logs = AdCallbackLog::query()
            ->with('rewardedClaim')
            ->when($this->outcome !== '', fn ($query) => $query->where('outcome', $this->outcome))
            ->when($this->signature !== '', fn ($query) => $query->where('signature_valid', $this->signature === 'valid'))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->latest('id')
            ->paginate(25);

        return view('livewire.admin.ads.verification-logs', [
            'logs' => $logs,
            'outcomes' => AdCallbackLog::query()->distinct()->orderBy('outcome')->pluck('outcome'),
        ]);
    }
}
