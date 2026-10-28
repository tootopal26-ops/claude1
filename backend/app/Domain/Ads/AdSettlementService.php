<?php

namespace App\Domain\Ads;

use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\AdRevenueReport;
use App\Models\AdSettlementAdjustment;
use App\Models\AdSettlementAllocation;
use App\Models\AdSettlementBatch;
use App\Models\AuditLog;
use App\Models\RewardedClaim;
use App\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The weekly Ads Earning settlement — a transparent aggregate allocation.
 *
 * Why aggregate: LevelPlay's per-impression revenue reaches the server only as the app's
 * own report, which a modified client could forge, so it is never used to pay anyone. The
 * revenue that is shared is the Ads Earning placement's revenue for the week as reported by
 * the provider (LevelPlay dashboard / reporting), entered by an Admin and marked finalized.
 *
 * The calculation, all in fixed-point decimal (bcmath), never floating point:
 *   business share  = revenue × business %          (USD)
 *   players' pool   = revenue − business share       (USD, so the two always add up)
 *   pool in INR     = players' pool × exchange rate  (rate, its source and time are stored)
 *   pool in coins   = pool in INR × coins per INR 1 (the Admin conversion policy)
 *   player's coins  = pool in coins × player's units ÷ all units, truncated to 0.01
 * A unit is one rewarded ad on the Ads Earning placement, verified by the provider's
 * callback, by an opted-in, eligible player, within the daily cap. The rounding remainder
 * and allocations below the Admin minimum are recorded on the batch, not paid.
 *
 * Crediting is idempotent: each allocation credits the Deposit balance with the key
 * "ads_earning:{batch}:{player}", so re-running a settlement never pays twice, a failed
 * credit can be retried safely, and a completed batch is never edited — a correction is a
 * separate, audited adjustment.
 */
class AdSettlementService
{
    public const string POLICY_VERSION = 'ads-earning-v1';

    public function __construct(
        private readonly WalletService $wallet,
        private readonly AdsEarningService $earning,
    ) {}

    /**
     * Opens the settlement for a period (defaults to the most recent completed week).
     * The shares and conversion in force now are recorded on the batch.
     *
     * @throws ValidationException
     */
    public function openBatch(?CarbonImmutable $start = null, ?CarbonImmutable $end = null, ?StaffUser $actor = null): AdSettlementBatch
    {
        if ($start === null || $end === null) {
            [$start, $end] = $this->earning->lastCompletedPeriod();
        }

        if ($end->greaterThan(now())) {
            throw ValidationException::withMessages(['period' => 'A week can only be settled after it has ended.']);
        }

        $batch = AdSettlementBatch::firstOrCreate(
            ['period_start' => $start, 'period_end' => $end],
            [
                'status' => 'pending',
                'business_share_pct' => config('minimax.ads_earning_business_share'),
                'user_share_pct' => config('minimax.ads_earning_user_share'),
                'coins_per_inr' => config('minimax.ads_earning_coins_per_inr'),
                'policy_version' => self::POLICY_VERSION,
                'created_by_staff_id' => $actor?->id,
            ],
        );

        if ($batch->wasRecentlyCreated && $actor !== null) {
            AuditLog::record('ads.settlement.opened', $actor, $batch, ['period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String()]);
        }

        return $batch;
    }

    /**
     * The finalized Ads Earning revenue reported for days inside the batch's period
     * (reports are per provider day, in the settlement timezone).
     */
    public function eligibleRevenue(AdSettlementBatch $batch): string
    {
        $sum = $this->eligibleReports($batch)->reduce(fn (string $total, AdRevenueReport $report) => bcadd($total, (string) $report->revenue_usd, 6), '0');

        return number_format((float) $sum, 6, '.', '');
    }

    /**
     * @return Collection<int, AdRevenueReport>
     */
    public function eligibleReports(AdSettlementBatch $batch): Collection
    {
        [$firstDay, $lastDay] = $this->localDays($batch);

        return AdRevenueReport::query()
            ->where('status', 'finalized')
            ->where('placement_key', 'ads_earning')
            ->whereDate('period_start', '>=', $firstDay)
            ->whereDate('period_end', '<=', $lastDay)
            ->orderBy('period_start')
            ->get();
    }

    /**
     * Calculates (or recalculates, before any credit) every allocation.
     *
     * @throws ValidationException
     */
    public function calculate(AdSettlementBatch $batch, string $usdInrRate, string $rateSource, StaffUser $actor): AdSettlementBatch
    {
        if (! in_array($batch->status, ['pending', 'calculated'], true)) {
            throw ValidationException::withMessages(['batch' => 'This settlement has already started crediting and can\'t be recalculated.']);
        }

        if (! is_numeric($usdInrRate) || bccomp($usdInrRate, '0', 4) <= 0 || bccomp($usdInrRate, '1000', 4) > 0) {
            throw ValidationException::withMessages(['usd_inr_rate' => 'Enter the USD to INR rate used for this settlement (for example 83.25).']);
        }

        if (trim($rateSource) === '') {
            throw ValidationException::withMessages(['rate_source' => 'Say where the exchange rate comes from.']);
        }

        $revenue = $this->eligibleRevenue($batch);
        if (bccomp($revenue, '0', 6) <= 0) {
            throw ValidationException::withMessages(['revenue' => 'There is no finalized Ads Earning revenue for this week yet. Enter the provider\'s figure and mark it finalized first.']);
        }

        return DB::transaction(function () use ($batch, $usdInrRate, $rateSource, $actor, $revenue) {
            /** @var AdSettlementBatch $locked */
            $locked = AdSettlementBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $units = RewardedClaim::query()
                ->where('purpose', 'ads_earning')
                ->where('status', 'verified')
                ->where('counts_for_earning', true)
                ->where('verified_at', '>=', $locked->period_start)
                ->where('verified_at', '<', $locked->period_end)
                ->selectRaw('user_id, count(*) as units')
                ->groupBy('user_id')
                ->pluck('units', 'user_id');

            $totalUnits = (int) $units->sum();
            $businessUsd = bcdiv(bcmul($revenue, (string) $locked->business_share_pct, 8), '100', 6);
            $poolUsd = bcsub($revenue, $businessUsd, 6);
            $poolInr = bcmul($poolUsd, $usdInrRate, 4);
            $poolCoins = bcmul($poolInr, (string) $locked->coins_per_inr, 4);
            $minimum = (string) config('minimax.ads_earning_min_payout_coins');

            $locked->allocations()->delete();

            $allocated = '0.00';
            $paid = '0.00';
            foreach ($units as $userId => $userUnits) {
                // bcmath truncates: floor to 0.01 coin for these non-negative amounts.
                $coins = $totalUnits > 0 ? bcdiv(bcmul($poolCoins, (string) $userUnits, 6), (string) $totalUnits, 2) : '0.00';
                $payable = bccomp($coins, $minimum, 2) >= 0 && bccomp($coins, '0', 2) > 0;

                AdSettlementAllocation::create([
                    'ad_settlement_batch_id' => $locked->id,
                    'user_id' => $userId,
                    'units' => $userUnits,
                    'coins' => $coins,
                    'status' => $payable ? 'pending' : 'below_minimum',
                ]);

                $allocated = bcadd($allocated, $coins, 2);
                if ($payable) {
                    $paid = bcadd($paid, $coins, 2);
                }
            }

            $locked->update([
                'status' => 'calculated',
                'eligible_revenue_usd' => $revenue,
                'revenue_sources' => $this->eligibleReports($locked)->map(fn (AdRevenueReport $report) => [
                    'id' => $report->id,
                    'period_start' => $report->period_start->toDateString(),
                    'period_end' => $report->period_end->toDateString(),
                    'provider' => $report->provider,
                    'revenue_usd' => (string) $report->revenue_usd,
                    'source' => $report->source,
                ])->values()->all(),
                'business_share_usd' => $businessUsd,
                'user_pool_usd' => $poolUsd,
                'usd_inr_rate' => $usdInrRate,
                'rate_source' => mb_substr(trim($rateSource), 0, 160),
                'rate_at' => now(),
                'user_pool_inr' => $poolInr,
                'total_units' => $totalUnits,
                'eligible_users' => $units->count(),
                'total_coins_allocated' => $paid,
                'remainder_coins' => bcsub($poolCoins, $paid, 4),
                'calculated_at' => now(),
            ]);

            AuditLog::record('ads.settlement.calculated', $actor, $locked, [
                'revenue_usd' => $revenue,
                'usd_inr_rate' => $usdInrRate,
                'rate_source' => $rateSource,
                'business_share_pct' => (string) $locked->business_share_pct,
                'user_share_pct' => (string) $locked->user_share_pct,
                'coins_per_inr' => (string) $locked->coins_per_inr,
                'total_units' => $totalUnits,
                'coins_allocated' => $paid,
                'coins_below_minimum_or_unallocated' => bcsub($poolCoins, $paid, 4),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Credits every pending (or previously failed) allocation. Safe to run again at any time.
     */
    public function credit(AdSettlementBatch $batch, ?StaffUser $actor = null): AdSettlementBatch
    {
        $batch = DB::transaction(function () use ($batch) {
            /** @var AdSettlementBatch $locked */
            $locked = AdSettlementBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if (! in_array($locked->status, ['calculated', 'crediting', 'failed'], true)) {
                throw ValidationException::withMessages(['batch' => 'Only a calculated settlement can be credited.']);
            }

            $locked->update(['status' => 'crediting']);

            return $locked;
        });

        $period = $batch->period_start->toDateString().' – '.$batch->period_end->toDateString();

        // Loaded up front (not chunked): each credit changes the status this query filters on.
        $batch->allocations()->whereIn('status', ['pending', 'failed'])->with('user')->get()->each(function (AdSettlementAllocation $allocation) use ($batch, $period) {
            try {
                $entry = $this->wallet->creditReward(
                    $allocation->user,
                    (string) $allocation->coins,
                    'ads_earning_reward',
                    "ads_earning:{$batch->id}:{$allocation->user_id}",
                    $allocation,
                    "Ads Earning {$period}",
                );

                $allocation->update(['status' => 'credited', 'wallet_ledger_entry_id' => $entry->id, 'credited_at' => now(), 'failure_reason' => null, 'attempts' => $allocation->attempts + 1]);
            } catch (\Throwable $e) {
                // Kept for a safe retry: the idempotency key means a later attempt cannot double-credit.
                $allocation->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255), 'attempts' => $allocation->attempts + 1]);
            }
        });

        $failed = $batch->allocations()->where('status', 'failed')->exists();
        $credited = $batch->allocations()->where('status', 'credited')->sum('coins');

        $batch->update([
            'status' => $failed ? 'failed' : 'completed',
            'total_coins_credited' => number_format((float) $credited, 2, '.', ''),
            'completed_at' => $failed ? null : now(),
        ]);

        AuditLog::record($failed ? 'ads.settlement.credit_incomplete' : 'ads.settlement.completed', $actor, $batch, [
            'coins_credited' => number_format((float) $credited, 2, '.', ''),
            'failed_allocations' => $batch->allocations()->where('status', 'failed')->count(),
        ]);

        return $batch->fresh();
    }

    /**
     * Records an explicit correction to a credited allocation of a completed settlement: its
     * own row (signed coins, required reason, who and when) and its own ledger entry through
     * the wallet's audited manual adjustment. The settlement and the original credit are
     * never edited. A deduction that would take the Deposit balance negative is refused.
     *
     * @throws ValidationException
     */
    public function adjust(AdSettlementAllocation $allocation, string $signedCoins, string $reason, StaffUser $actor): AdSettlementAdjustment
    {
        $reason = trim($reason);

        if (! is_numeric($signedCoins) || ! preg_match('/^-?\d+(\.\d{1,2})?$/', trim($signedCoins)) || bccomp(trim($signedCoins), '0', 2) === 0) {
            throw ValidationException::withMessages(['coins' => 'Enter a non-zero amount with up to 2 decimals (negative to take coins back).']);
        }
        if (bccomp(ltrim(trim($signedCoins), '-'), '100000', 2) > 0) {
            throw ValidationException::withMessages(['coins' => 'An adjustment can be at most 100,000 coins.']);
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Say why (5–255 characters). Players see this reason in their history.']);
        }
        if ($allocation->batch->status !== 'completed' || $allocation->status !== 'credited') {
            throw ValidationException::withMessages(['coins' => 'Only a credited allocation of a completed settlement can be adjusted.']);
        }

        $signedCoins = number_format((float) trim($signedCoins), 2, '.', '');
        $period = $allocation->batch->period_start->toDateString().' – '.$allocation->batch->period_end->toDateString();

        try {
            return DB::transaction(function () use ($allocation, $signedCoins, $reason, $actor, $period) {
                $adjustment = AdSettlementAdjustment::create([
                    'ad_settlement_allocation_id' => $allocation->id,
                    'user_id' => $allocation->user_id,
                    'coins' => $signedCoins,
                    'reason' => $reason,
                    'created_by_staff_id' => $actor->id,
                ]);

                $entry = $this->wallet->manualAdjust(
                    $allocation->user,
                    $signedCoins,
                    'deposit',
                    "Ads Earning adjustment ({$period}): {$reason}",
                    $actor,
                    "ads_earning_adjustment:{$adjustment->id}",
                );

                $adjustment->update(['wallet_ledger_entry_id' => $entry->id]);

                AuditLog::record('ads.settlement.adjusted', $actor, $allocation->batch, [
                    'allocation_id' => $allocation->id,
                    'user_id' => $allocation->user_id,
                    'coins' => $signedCoins,
                    'reason' => $reason,
                    'ledger_entry_id' => $entry->id,
                ]);

                return $adjustment;
            });
        } catch (InsufficientWalletBalanceException) {
            throw ValidationException::withMessages(['coins' => 'That would take the player\'s Deposit balance below zero.']);
        }
    }

    /**
     * @return array{0: string, 1: string} first and last local day of the batch period
     */
    private function localDays(AdSettlementBatch $batch): array
    {
        $timezone = (string) config('minimax.ads_earning_timezone');

        return [
            CarbonImmutable::parse($batch->period_start)->setTimezone($timezone)->toDateString(),
            CarbonImmutable::parse($batch->period_end)->setTimezone($timezone)->subSecond()->toDateString(),
        ];
    }
}
