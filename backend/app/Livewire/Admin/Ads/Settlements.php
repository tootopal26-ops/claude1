<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Ads\AdSettlementService;
use App\Jobs\CreditAdSettlementJob;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AdSettlementAllocation;
use App\Models\AdSettlementBatch;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ads & Monetization > Weekly settlements. The scheduler opens each finished week as a
 * pending batch; an Admin calculates it (with the USD→INR rate and its source) once the
 * provider's revenue for the week is finalized, reviews the allocations, then credits it —
 * in a queued, unique, idempotent job, so a retry or a double click never pays twice.
 * Completed batches are read-only.
 */
#[Layout('components.layouts.admin')]
class Settlements extends Component
{
    use AdsPage;
    use WithPagination;

    #[Url]
    public ?int $batch = null;

    public string $rate = '';

    public string $rateSource = '';

    public function select(int $id): void
    {
        $this->batch = $id;
        $this->resetErrorBag();
        $this->resetPage('allocations');
    }

    public function openLastWeek(AdSettlementService $settlements): void
    {
        $this->ensureCanManage();

        try {
            $batch = $settlements->openBatch(actor: $this->actor());
        } catch (ValidationException $exception) {
            $this->addError('batch', collect($exception->errors())->flatten()->first());

            return;
        }

        $this->batch = $batch->id;
        session()->flash('status', "Settlement #{$batch->id} is open for review.");
    }

    public function calculate(AdSettlementService $settlements): void
    {
        $this->ensureCanManage();
        $batch = AdSettlementBatch::findOrFail($this->batch);

        try {
            $settlements->calculate($batch, trim($this->rate), trim($this->rateSource), $this->actor());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(['usd_inr_rate' => 'rate', 'rate_source' => 'rateSource'][$field] ?? 'batch', $messages[0]);
            }

            return;
        }

        session()->flash('status', 'Allocations calculated. Review them, then credit the settlement.');
    }

    public function credit(): void
    {
        $this->ensureCanManage();
        $batch = AdSettlementBatch::findOrFail($this->batch);

        if (! in_array($batch->status, ['calculated', 'crediting', 'failed'], true)) {
            $this->addError('batch', 'Only a calculated settlement can be credited.');

            return;
        }

        AuditLog::record($batch->status === 'failed' ? 'ads.settlement.retry_requested' : 'ads.settlement.credit_requested', $this->actor(), $batch, [
            'coins_to_credit' => (string) $batch->total_coins_allocated,
        ]);
        CreditAdSettlementJob::dispatch($batch->id, $this->actor()->id);

        session()->flash('status', 'Crediting has started. Each player is credited once; refresh to see progress.');
    }

    public function render()
    {
        $selected = $this->batch ? AdSettlementBatch::with('createdBy')->find($this->batch) : null;

        return view('livewire.admin.ads.settlements', [
            'batches' => AdSettlementBatch::latest('period_start')->paginate(10, pageName: 'batches'),
            'selected' => $selected,
            'allocations' => $selected
                ? AdSettlementAllocation::with('user')->where('ad_settlement_batch_id', $selected->id)->orderByDesc('coins')->paginate(25, pageName: 'allocations')
                : null,
            'revenue' => $selected ? app(AdSettlementService::class)->eligibleRevenue($selected) : null,
            'canManage' => $this->canManage(),
            'timezone' => (string) config('minimax.ads_earning_timezone'),
        ]);
    }
}
