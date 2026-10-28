<?php

namespace App\Domain\Ads;

use App\Models\AdsEarningPreference;
use App\Models\AdSettlementAllocation;
use App\Models\AdSettlementBatch;
use App\Models\RewardedClaim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Ads Earning, the weekly revenue-share model ("Model A"):
 *
 *  - A player opts in (if Admin lets players switch it themselves) once their successful
 *    deposits reach the Admin minimum (50 by default).
 *  - Each rewarded ad they then complete on the Ads Earning placement, verified by the ad
 *    network's server callback, is one contribution unit — up to a daily cap. Units are
 *    decided once, when the callback is verified, from the player's state at that moment:
 *    switching on later never adds past ads, switching off never removes them.
 *  - Only that placement's revenue is shared. Paying players for viewing ads is allowed
 *    only through rewarded inventory, so banner, native, interstitial and app-open
 *    impressions never earn anything here.
 *  - A weekly settlement (AdSettlementService) splits the Admin-entered, provider-reported
 *    revenue for the period by the business / players' shares and divides the players' pool
 *    by units. Nothing is credited before that, and no per-ad amount is promised.
 */
class AdsEarningService
{
    public function __construct(
        private readonly AdsConfig $ads,
        private readonly QualifyingDeposits $deposits,
    ) {}

    public function featureEnabled(): bool
    {
        return (bool) config('minimax.ads_earning_enabled') && ! config('minimax.ads_earning_paused');
    }

    /** New earning ads can be started right now (feature on, not paused, placement live). */
    public function featureActive(): bool
    {
        return $this->featureEnabled() && $this->ads->isPurposeActive('ads_earning');
    }

    public function isOptedIn(User $user): bool
    {
        return (bool) AdsEarningPreference::where('user_id', $user->id)->value('enabled');
    }

    /**
     * @return array{total: string, required: string, remaining: string, eligible: bool}
     */
    public function eligibility(User $user): array
    {
        return $this->deposits->progress($user, (string) config('minimax.ads_earning_min_deposit'));
    }

    /**
     * @throws ValidationException
     */
    public function setPreference(User $user, bool $enabled): AdsEarningPreference
    {
        if (! config('minimax.ads_earning_user_toggle_allowed')) {
            throw ValidationException::withMessages(['enabled' => 'Ads Earning can\'t be switched from the app right now.']);
        }

        if ($enabled) {
            if (! $this->featureEnabled()) {
                throw ValidationException::withMessages(['enabled' => 'Ads Earning isn\'t available right now.']);
            }

            $eligibility = $this->eligibility($user);
            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages(['enabled' => "Complete {$eligibility['remaining']} more in successful deposits to unlock Ads Earning."]);
            }
        }

        $preference = AdsEarningPreference::firstOrNew(['user_id' => $user->id]);

        if ((bool) $preference->enabled !== $enabled || ! $preference->exists) {
            $preference->enabled = $enabled;
            if ($enabled) {
                $preference->enabled_at = now();
            } else {
                $preference->disabled_at = now();
            }
            $preference->save();
        }

        return $preference;
    }

    /**
     * Throws when a new earning ad must not start for this player.
     *
     * @throws ValidationException
     */
    public function assertCanWatch(User $user): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['ads' => $message]);

        if (! $this->featureActive()) {
            $fail('Ads Earning isn\'t available right now.');
        }
        if (! $this->isOptedIn($user)) {
            $fail('Switch Ads Earning on first.');
        }
        if (! $this->eligibility($user)['eligible']) {
            $fail('Ads Earning unlocks after your successful deposits reach the minimum.');
        }
        if ($this->unitsToday($user) >= (int) config('minimax.ads_earning_daily_view_limit')) {
            $fail('You\'ve reached today\'s Ads Earning limit. Come back tomorrow.');
        }
    }

    /** Decided at verification time: does this completion count as an earning unit? */
    public function countsNow(User $user): bool
    {
        return $this->featureEnabled()
            && $this->isOptedIn($user)
            && $this->eligibility($user)['eligible']
            && $this->unitsToday($user) < (int) config('minimax.ads_earning_daily_view_limit');
    }

    public function unitsToday(User $user): int
    {
        $start = CarbonImmutable::now($this->timezone())->startOfDay()->utc();

        return RewardedClaim::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'ads_earning')
            ->where('status', 'verified')
            ->where('counts_for_earning', true)
            ->where('verified_at', '>=', $start)
            ->count();
    }

    /**
     * The settlement period that contains $at: from the most recent weekly cutoff
     * (Admin's day and time, in the settlement timezone) to the next one. Returned in UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodContaining(?CarbonImmutable $at = null): array
    {
        $local = ($at ?? CarbonImmutable::now())->setTimezone($this->timezone());
        [$hour, $minute] = array_map('intval', explode(':', (string) config('minimax.ads_earning_settlement_time')));

        $cutoff = $local->startOfWeek(CarbonImmutable::MONDAY)
            ->addDays((int) config('minimax.ads_earning_settlement_day') - 1)
            ->setTime($hour, $minute);

        if ($cutoff->greaterThan($local)) {
            $cutoff = $cutoff->subWeek();
        }

        return [$cutoff->utc(), $cutoff->addWeek()->utc()];
    }

    /** The most recent period that has fully ended (the one a settlement would cover now). */
    public function lastCompletedPeriod(): array
    {
        [$start] = $this->periodContaining();

        return [$start->subWeek(), $start];
    }

    public function unitsInPeriod(User $user, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return RewardedClaim::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'ads_earning')
            ->where('status', 'verified')
            ->where('counts_for_earning', true)
            ->where('verified_at', '>=', $start)
            ->where('verified_at', '<', $end)
            ->count();
    }

    /**
     * Everything the app's Ads Earning screen shows, all from the server.
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        [$start, $end] = $this->periodContaining();
        $eligibility = $this->eligibility($user);

        $credited = AdSettlementAllocation::where('user_id', $user->id)->where('status', 'credited');
        $pending = AdSettlementAllocation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'failed'])
            ->whereHas('batch', fn ($batch) => $batch->whereIn('status', ['calculated', 'crediting', 'failed']));
        $lastSettled = AdSettlementBatch::where('status', 'completed')->latest('completed_at')->value('completed_at');

        return [
            'feature_enabled' => (bool) config('minimax.ads_earning_enabled'),
            'paused' => (bool) config('minimax.ads_earning_paused'),
            'available' => $this->featureActive(),
            'can_toggle' => (bool) config('minimax.ads_earning_user_toggle_allowed'),
            'opted_in' => $this->isOptedIn($user),
            'eligibility' => $eligibility,
            'period' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'units_this_period' => $this->unitsInPeriod($user, $start, $end),
            'units_today' => $this->unitsToday($user),
            'daily_limit' => (int) config('minimax.ads_earning_daily_view_limit'),
            'pending_coins' => number_format((float) (clone $pending)->sum('coins'), 2, '.', ''),
            'credited_coins' => number_format((float) (clone $credited)->sum('coins'), 2, '.', ''),
            'last_settlement_at' => $lastSettled?->toIso8601String(),
            'explanation' => (string) config('minimax.ads_earning_explanation'),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, AdSettlementAllocation>
     */
    public function history(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return AdSettlementAllocation::query()
            ->with('batch')
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate($perPage);
    }

    private function timezone(): string
    {
        return (string) config('minimax.ads_earning_timezone');
    }
}
