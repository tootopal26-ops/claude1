<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Ads\AdsAnalytics;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Models\AdPlacement;
use App\Models\AdRevenueReport;
use App\Models\AdSettlementBatch;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ads & Monetization > Revenue reports: the provider's own revenue figures (from the
 * LevelPlay dashboard or its reporting export), entered by an Admin. A report starts as
 * "estimated"; only once marked "finalized" can a settlement use it, and only the Ads
 * Earning placement's finalized revenue is ever shared with players. Finalized figures are
 * never edited: a wrong one is marked disputed (before any settlement credits it) and a
 * corrected report is entered, all audit-logged. Each row shows the app's own SDK estimate
 * for the same days for reconciliation.
 */
#[Layout('components.layouts.admin')]
class RevenueReports extends Component
{
    use AdsPage;
    use WithPagination;

    public string $periodStart = '';

    public string $periodEnd = '';

    public string $placementKey = 'ads_earning';

    public string $revenue = '';

    public string $source = '';

    public string $notes = '';

    public string $statusFilter = '';

    public function mount(): void
    {
        $yesterday = CarbonImmutable::now((string) config('minimax.ads_earning_timezone'))->subDay()->toDateString();
        $this->periodStart = $yesterday;
        $this->periodEnd = $yesterday;
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function add(): void
    {
        $this->ensureCanManage();
        $today = CarbonImmutable::now((string) config('minimax.ads_earning_timezone'))->toDateString();

        $validated = $this->validate([
            'periodStart' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'periodEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodStart', 'before_or_equal:'.$today],
            'placementKey' => ['nullable', Rule::exists('ad_placements', 'key')],
            'revenue' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^\d+(\.\d{1,6})?$/'],
            'source' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['revenue.regex' => 'Enter the amount in US dollars with up to 6 decimal places.'], [
            'periodStart' => 'first day', 'periodEnd' => 'last day', 'placementKey' => 'placement',
        ]);

        $report = AdRevenueReport::create([
            'period_start' => $validated['periodStart'],
            'period_end' => $validated['periodEnd'],
            'provider' => 'levelplay',
            'placement_key' => $validated['placementKey'] ?: null,
            'revenue_usd' => $validated['revenue'],
            'status' => 'estimated',
            'source' => trim($validated['source']),
            'notes' => $validated['notes'] ?: null,
            'entered_by_staff_id' => $this->actor()->id,
        ]);

        AuditLog::record('ads.revenue_report.created', $this->actor(), $report, $report->only(['period_start', 'period_end', 'placement_key', 'revenue_usd', 'source']));

        $this->reset(['revenue', 'source', 'notes']);
        session()->flash('status', 'Revenue report added as estimated. Mark it finalized once the provider\'s figure is final.');
    }

    public function finalize(int $id): void
    {
        $this->ensureCanManage();
        $report = AdRevenueReport::findOrFail($id);

        if ($report->status !== 'estimated') {
            $this->addError('reports', 'Only an estimated report can be finalized.');

            return;
        }

        // Two finalized figures for the same placement and days would be counted twice.
        $overlap = AdRevenueReport::query()
            ->where('id', '!=', $report->id)
            ->where('status', 'finalized')
            ->where('placement_key', $report->placement_key)
            ->whereDate('period_start', '<=', $report->period_end)
            ->whereDate('period_end', '>=', $report->period_start)
            ->exists();

        if ($overlap) {
            $this->addError('reports', 'Another finalized report already covers some of these days for this placement. Dispute that one first if this replaces it.');

            return;
        }

        $report->update(['status' => 'finalized']);
        AuditLog::record('ads.revenue_report.finalized', $this->actor(), $report, ['revenue_usd' => (string) $report->revenue_usd, 'placement_key' => $report->placement_key]);
        session()->flash('status', 'Report finalized. A settlement can now use it.');
    }

    public function dispute(int $id): void
    {
        $this->ensureCanManage();
        $report = AdRevenueReport::findOrFail($id);

        if ($report->status === 'disputed') {
            return;
        }

        if ($report->status === 'finalized' && $this->usedBySettlement($report)) {
            $this->addError('reports', 'A settlement that has started crediting already used this figure. Record a correction as a separate adjustment instead.');

            return;
        }

        $previous = $report->status;
        $report->update(['status' => 'disputed']);
        AuditLog::record('ads.revenue_report.disputed', $this->actor(), $report, ['previous_status' => $previous, 'revenue_usd' => (string) $report->revenue_usd]);
        session()->flash('status', 'Report marked disputed. It is excluded from settlements.');
    }

    public function render(AdsAnalytics $analytics)
    {
        $timezone = $analytics->timezone();
        $reports = AdRevenueReport::query()
            ->with('enteredBy')
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('period_end')
            ->latest('id')
            ->paginate(20);

        $estimates = $reports->getCollection()->mapWithKeys(fn (AdRevenueReport $report) => [
            $report->id => $analytics->estimatedRevenue(
                CarbonImmutable::parse($report->period_start->toDateString(), $timezone)->startOfDay()->utc(),
                CarbonImmutable::parse($report->period_end->toDateString(), $timezone)->addDay()->startOfDay()->utc(),
                ['placement' => $report->placement_key],
            ),
        ]);

        return view('livewire.admin.ads.revenue-reports', [
            'reports' => $reports,
            'estimates' => $estimates,
            'placements' => AdPlacement::orderBy('name')->get(['key', 'name']),
            'canManage' => $this->canManage(),
        ]);
    }

    private function usedBySettlement(AdRevenueReport $report): bool
    {
        $timezone = (string) config('minimax.ads_earning_timezone');

        return AdSettlementBatch::query()
            ->whereIn('status', ['crediting', 'completed', 'failed'])
            ->where('period_start', '<', CarbonImmutable::parse($report->period_end->toDateString(), $timezone)->addDay()->startOfDay()->utc())
            ->where('period_end', '>', CarbonImmutable::parse($report->period_start->toDateString(), $timezone)->startOfDay()->utc())
            ->exists();
    }
}
