<?php

namespace App\Domain\Ads;

use App\Domain\Wallet\WalletService;
use App\Models\AdEvent;
use App\Models\AdPlacement;
use App\Models\AdRevenueReport;
use App\Models\AdsEarningPreference;
use App\Models\AdSettlementAllocation;
use App\Models\AdSettlementBatch;
use App\Models\DepositOrder;
use App\Models\RewardedClaim;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind Admin > Ads & Monetization, all read from the database — nothing is
 * estimated here that isn't labelled so. Four kinds of figure are kept apart on purpose:
 *
 *  - SDK telemetry (ad_events): requests, loads, no-fills, impressions and the SDK's own
 *    impression-revenue estimate. App-reported, so "estimated"; test events are excluded
 *    and counted separately.
 *  - Verified rewarded completions (rewarded_claims verified by the network's signed
 *    callback). Simulated QA completions are excluded and counted separately.
 *  - Provider-reported revenue (ad_revenue_reports) — the only revenue that is ever
 *    settled, and only once an Admin marks it finalized.
 *  - Coins actually credited (wallet ledger reward entries and settlement allocations).
 */
class AdsAnalytics
{
    public const array RANGES = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'this_week' => 'This week',
        'last_30_days' => 'Last 30 days',
        'this_month' => 'This month',
        'custom' => 'Custom range',
    ];

    public const array REWARD_TYPES = [
        'tournament_ticket' => 'Tournament tickets',
        'daily_reward' => 'Daily login reward',
        'ads_earning' => 'Ads Earning',
        'redeem_code' => 'Redeem codes',
        'campaign' => 'Reward campaigns',
    ];

    public function timezone(): string
    {
        return (string) config('minimax.ads_earning_timezone');
    }

    /**
     * A named range (or a custom from/to, inclusive local days) as UTC instants [start, end).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(string $preset, ?string $from = null, ?string $to = null): array
    {
        $now = CarbonImmutable::now($this->timezone());
        $today = $now->startOfDay();

        [$start, $end] = match ($preset) {
            'yesterday' => [$today->subDay(), $today],
            'last_7_days' => [$today->subDays(6), $today->addDay()],
            'this_week' => [$now->startOfWeek(CarbonImmutable::MONDAY), $today->addDay()],
            'last_30_days' => [$today->subDays(29), $today->addDay()],
            'this_month' => [$now->startOfMonth(), $today->addDay()],
            'custom' => $this->customRange($from, $to, $today),
            default => [$today, $today->addDay()],
        };

        return [$start->utc(), $end->utc()];
    }

    /**
     * @param  array{placement?: ?string, network?: ?string, user_id?: ?int, purpose?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function overview(CarbonImmutable $start, CarbonImmutable $end, array $filters = []): array
    {
        $events = fn () => $this->events($start, $end, $filters);
        $count = fn (string $event) => (clone $events())->where('is_test', false)->where('event', $event)->count();

        $rewardedPlacements = AdPlacement::where('format', 'rewarded')->pluck('key')->all();
        $impressions = $count('impression');
        $rewardedImpressions = (clone $events())->where('is_test', false)->where('event', 'impression')->whereIn('placement_key', $rewardedPlacements)->count();
        $requests = $count('requested');
        $failed = (clone $events())->where('is_test', false)->whereIn('event', ['no_fill', 'load_failed', 'display_failed'])->count();

        $claims = fn () => $this->claims($start, $end, $filters);
        $opened = (clone $claims())->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();
        $verified = $this->verifiedClaims($start, $end, $filters)->where('verification_method', 'levelplay_s2s')->count();
        $simulated = $this->verifiedClaims($start, $end, $filters)->where('verification_method', 'simulated')->count();

        $finalized = $this->providerRevenue($start, $end, $filters['placement'] ?? null, 'finalized');
        $businessPct = (string) config('minimax.ads_earning_business_share');
        $businessShare = bcdiv(bcmul($finalized, $businessPct, 8), '100', 6);

        $minDeposit = (string) config('minimax.ads_earning_min_deposit');
        $eligibleUsers = DB::query()->fromSub(
            DepositOrder::query()->where('status', 'success')->groupBy('user_id')->havingRaw('SUM(amount) >= CAST(? AS DECIMAL(12,2))', [$minDeposit])->select('user_id'),
            'eligible',
        )->count();
        $players = User::count();

        $creditedRewardCoins = WalletLedgerEntry::query()
            ->whereIn('type', WalletService::REWARD_TYPES)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->sum('amount');

        return [
            'estimate' => [
                'today' => $this->estimatedRevenue(...$this->range('today'), filters: $filters),
                'yesterday' => $this->estimatedRevenue(...$this->range('yesterday'), filters: $filters),
                'this_week' => $this->estimatedRevenue(...$this->range('this_week'), filters: $filters),
                'this_month' => $this->estimatedRevenue(...$this->range('this_month'), filters: $filters),
                'range' => $this->estimatedRevenue($start, $end, $filters),
            ],
            'provider_revenue_finalized' => $finalized,
            'provider_revenue_estimated' => $this->providerRevenue($start, $end, $filters['placement'] ?? null, 'estimated'),
            'business_share_usd' => $businessShare,
            'user_pool_usd' => bcsub($finalized, $businessShare, 6),
            'requests' => $requests,
            'loads' => $count('loaded'),
            'impressions' => $impressions,
            'rewarded_impressions' => $rewardedImpressions,
            'failed_requests' => $failed,
            'no_fill_rate' => $requests > 0 ? round(100 * $count('no_fill') / $requests, 1) : null,
            'claims_opened' => $opened,
            'rewarded_completed' => $verified,
            'simulated_completed' => $simulated,
            'completion_rate' => $opened > 0 ? round(100 * $verified / $opened, 1) : null,
            'test_events' => (clone $events())->where('is_test', true)->count(),
            'unique_viewers' => (clone $events())->where('is_test', false)->where('event', 'impression')->whereNotNull('user_id')->distinct()->count('user_id'),
            'opted_in_users' => $optedIn = AdsEarningPreference::where('enabled', true)->count(),
            'opt_in_rate' => $eligibleUsers > 0 ? round(100 * $optedIn / $eligibleUsers, 1) : null,
            'sdk_errors' => (clone $events())->where('is_test', false)->whereIn('event', ['init_failed', 'load_failed', 'display_failed'])->count(),
            'eligible_users' => $eligibleUsers,
            'not_eligible_users' => max(0, $players - $eligibleUsers),
            'units_pending_calculation' => $this->unitsAwaitingCalculation(),
            'coins_pending_settlement' => $this->coins(AdSettlementAllocation::whereIn('status', ['pending', 'failed'])),
            'coins_credited_settlements' => $this->coins(AdSettlementAllocation::where('status', 'credited')),
            'failed_allocations' => AdSettlementAllocation::where('status', 'failed')->count(),
            'disputed_revenue_reports' => AdRevenueReport::where('status', 'disputed')->count(),
            'reward_coins_credited' => number_format((float) $creditedRewardCoins, 2, '.', ''),
        ];
    }

    /**
     * Per local day: impressions and verified rewarded completions (for the chart).
     *
     * @param  array{placement?: ?string, network?: ?string, user_id?: ?int, purpose?: ?string}  $filters
     * @return list<array{day: string, impressions: int, completed: int}>
     */
    public function daily(CarbonImmutable $start, CarbonImmutable $end, array $filters = []): array
    {
        $days = [];
        for ($day = $start->setTimezone($this->timezone())->startOfDay(); $day->lessThan($end) && count($days) < 62; $day = $day->addDay()) {
            $days[$day->toDateString()] = ['day' => $day->toDateString(), 'impressions' => 0, 'completed' => 0];
        }

        $impressions = $this->events($start, $end, $filters)->where('is_test', false)->where('event', 'impression')
            ->selectRaw($this->localDay('occurred_at').' as day, COUNT(*) as total')->groupBy('day')->pluck('total', 'day');
        $completed = $this->verifiedClaims($start, $end, $filters)->where('verification_method', 'levelplay_s2s')
            ->selectRaw($this->localDay('verified_at').' as day, COUNT(*) as total')->groupBy('day')->pluck('total', 'day');

        foreach ($days as $key => $row) {
            $days[$key]['impressions'] = (int) ($impressions[$key] ?? 0);
            $days[$key]['completed'] = (int) ($completed[$key] ?? 0);
        }

        return array_values($days);
    }

    /**
     * Requests, loads, no-fills, errors, impressions, estimated revenue and verified
     * completions for each placement — distinct events, never added together.
     *
     * @param  array{placement?: ?string, network?: ?string, user_id?: ?int, purpose?: ?string}  $filters
     * @return list<array<string, mixed>>
     */
    public function byPlacement(CarbonImmutable $start, CarbonImmutable $end, array $filters = []): array
    {
        $rows = $this->events($start, $end, $filters)
            ->where('is_test', false)
            ->selectRaw('placement_key, event, COUNT(*) as total, SUM(revenue_usd) as revenue')
            ->groupBy('placement_key', 'event')
            ->get()
            ->groupBy('placement_key');

        $completed = $this->verifiedClaims($start, $end, $filters)
            ->where('verification_method', 'levelplay_s2s')
            ->selectRaw('placement_key, COUNT(*) as total')
            ->groupBy('placement_key')
            ->pluck('total', 'placement_key');

        return AdPlacement::orderBy('id')->get()->map(function (AdPlacement $placement) use ($rows, $completed) {
            $events = ($rows[$placement->key] ?? collect())->keyBy('event');
            $get = fn (string $event) => (int) ($events[$event]->total ?? 0);
            $requests = $get('requested');
            $loads = $get('loaded');

            return [
                'key' => $placement->key,
                'name' => $placement->name,
                'format' => $placement->format,
                'enabled' => $placement->is_enabled && $placement->is_supported,
                'requests' => $requests,
                'loads' => $loads,
                'no_fill' => $get('no_fill'),
                'errors' => $get('load_failed') + $get('display_failed'),
                'impressions' => $get('impression'),
                'fill_rate' => $requests > 0 ? round(100 * $loads / $requests, 1) : null,
                'estimated_revenue_usd' => number_format((float) ($events['impression']->revenue ?? 0), 6, '.', ''),
                'verified_completions' => (int) ($completed[$placement->key] ?? 0),
            ];
        })->all();
    }

    /**
     * @param  array{placement?: ?string, network?: ?string, user_id?: ?int, purpose?: ?string}  $filters
     * @return list<array{network: string, impressions: int, estimated_revenue_usd: string}>
     */
    public function byNetwork(CarbonImmutable $start, CarbonImmutable $end, array $filters = []): array
    {
        return $this->events($start, $end, $filters)
            ->where('is_test', false)
            ->where('event', 'impression')
            ->selectRaw('COALESCE(ad_network, ?) as network, COUNT(*) as impressions, SUM(revenue_usd) as revenue', ['unknown'])
            ->groupBy('network')
            ->orderByDesc('impressions')
            ->get()
            ->map(fn ($row) => ['network' => (string) $row->network, 'impressions' => (int) $row->impressions, 'estimated_revenue_usd' => number_format((float) $row->revenue, 6, '.', '')])
            ->all();
    }

    /** @return list<string> */
    public function networks(): array
    {
        return AdEvent::query()->whereNotNull('ad_network')->distinct()->orderBy('ad_network')->limit(50)->pluck('ad_network')->all();
    }

    /**
     * Everything Admin needs about one player's ad activity.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $eligibility = app(AdsEarningService::class)->eligibility($user);
        $events = AdEvent::where('user_id', $user->id)->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->where('is_test', false);
        $claims = RewardedClaim::where('user_id', $user->id)->where('created_at', '>=', $start)->where('created_at', '<', $end);

        return [
            'opted_in' => app(AdsEarningService::class)->isOptedIn($user),
            'eligibility' => $eligibility,
            'impressions' => (clone $events)->where('event', 'impression')->count(),
            'rewarded_started' => (clone $claims)->count(),
            'rewarded_completed' => (clone $claims)->where('status', 'verified')->where('verification_method', 'levelplay_s2s')->count(),
            'rewarded_simulated' => (clone $claims)->where('status', 'verified')->where('verification_method', 'simulated')->count(),
            'rewarded_failed' => (clone $claims)->whereIn('status', ['rejected', 'expired', 'cancelled'])->count(),
            'rewards_credited' => (clone $claims)->where('status', 'verified')->whereNotNull('wallet_ledger_entry_id')->count(),
            'pending_allocation_coins' => $this->coins(AdSettlementAllocation::where('user_id', $user->id)->whereIn('status', ['pending', 'failed'])),
            'credited_reward_coins' => number_format((float) WalletLedgerEntry::where('user_id', $user->id)->whereIn('type', WalletService::REWARD_TYPES)->sum('amount'), 2, '.', ''),
            'last_event_at' => AdEvent::where('user_id', $user->id)->where('is_test', false)->max('occurred_at'),
            'last_settlement_at' => AdSettlementAllocation::where('user_id', $user->id)->where('status', 'credited')->max('credited_at'),
        ];
    }

    /** Verified Ads Earning units not yet in a calculated settlement. */
    public function unitsAwaitingCalculation(): int
    {
        $settled = AdSettlementBatch::whereIn('status', ['calculated', 'crediting', 'completed', 'failed'])->get(['period_start', 'period_end']);

        return RewardedClaim::query()
            ->where('purpose', 'ads_earning')
            ->where('status', 'verified')
            ->where('counts_for_earning', true)
            ->where(function (Builder $query) use ($settled) {
                foreach ($settled as $batch) {
                    $query->where(fn (Builder $inner) => $inner->where('verified_at', '<', $batch->period_start)->orWhere('verified_at', '>=', $batch->period_end));
                }
            })
            ->count();
    }

    /**
     * The SDK's own revenue estimate (production events only) — never used for payouts.
     *
     * @param  array{placement?: ?string, network?: ?string, user_id?: ?int, purpose?: ?string}  $filters
     */
    public function estimatedRevenue(CarbonImmutable $start, CarbonImmutable $end, array $filters = []): string
    {
        $sum = $this->events($start, $end, $filters)->where('is_test', false)->where('event', 'impression')->sum('revenue_usd');

        return number_format((float) $sum, 6, '.', '');
    }

    public function providerRevenue(CarbonImmutable $start, CarbonImmutable $end, ?string $placement, string $status): string
    {
        $timezone = $this->timezone();
        $sum = AdRevenueReport::query()
            ->where('status', $status)
            ->whereDate('period_start', '>=', $start->setTimezone($timezone)->toDateString())
            ->whereDate('period_end', '<=', $end->setTimezone($timezone)->subSecond()->toDateString())
            ->when($placement, fn (Builder $query, string $key) => $query->where('placement_key', $key))
            ->sum('revenue_usd');

        return number_format((float) $sum, 6, '.', '');
    }

    /**
     * @param  array{placement?: ?string, network?: ?string, user_id?: ?int, purpose?: ?string}  $filters
     * @return Builder<AdEvent>
     */
    private function events(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        return AdEvent::query()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->when($filters['placement'] ?? null, fn (Builder $query, string $key) => $query->where('placement_key', $key))
            ->when($filters['network'] ?? null, fn (Builder $query, string $network) => $query->where('ad_network', $network))
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($filters['purpose'] ?? null, fn (Builder $query, string $purpose) => $query->where('placement_key', AdsConfig::PURPOSE_PLACEMENTS[$purpose] ?? '-'));
    }

    /**
     * @param  array{placement?: ?string, network?: ?string, user_id?: ?int, purpose?: ?string}  $filters
     * @return Builder<RewardedClaim>
     */
    private function claims(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        return RewardedClaim::query()
            ->when($filters['placement'] ?? null, fn (Builder $query, string $key) => $query->where('placement_key', $key))
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($filters['purpose'] ?? null, fn (Builder $query, string $purpose) => $query->where('purpose', $purpose));
    }

    /**
     * @param  array{placement?: ?string, network?: ?string, user_id?: ?int, purpose?: ?string}  $filters
     * @return Builder<RewardedClaim>
     */
    private function verifiedClaims(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        return $this->claims($start, $end, $filters)->where('status', 'verified')->where('verified_at', '>=', $start)->where('verified_at', '<', $end);
    }

    /**
     * @param  Builder<AdSettlementAllocation>  $query
     */
    private function coins(Builder $query): string
    {
        return number_format((float) $query->sum('coins'), 2, '.', '');
    }

    /**
     * SQL for "the local calendar day of this timestamp column" in the reporting timezone,
     * using its current UTC offset (exact for Asia/Kolkata, which has no daylight saving).
     */
    private function localDay(string $column): string
    {
        $minutes = (int) CarbonImmutable::now($this->timezone())->utcOffset();

        return DB::connection()->getDriverName() === 'sqlite'
            ? sprintf("date(%s, '%+d minutes')", $column, $minutes)
            : sprintf('DATE(DATE_ADD(%s, INTERVAL %d MINUTE))', $column, $minutes);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function customRange(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $timezone = $this->timezone();
        $start = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? CarbonImmutable::parse($from, $timezone)->startOfDay() : $today->subDays(6);
        $end = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay() : $today->addDay();

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addDay();
        }

        // Keep reports bounded: at most a year at a time.
        if ($start->diffInDays($end) > 366) {
            $start = $end->subDays(366);
        }

        return [$start, $end];
    }
}
