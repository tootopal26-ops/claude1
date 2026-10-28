<?php

namespace App\Domain\Ads;

use App\Domain\Wallet\WalletService;
use App\Models\DailyRewardClaim;
use App\Models\DailyRewardDismissal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The daily login reward. The server decides everything:
 *  - "today" is the calendar day in the Admin timezone (Asia/Kolkata by default), never the
 *    phone's clock;
 *  - the amount is drawn at random, by the server, within the Admin range, the first time a
 *    player starts today's claim, and stored — every retry that day gets the same amount;
 *  - (player, day) is unique in the database, so at most one reward per player per day,
 *    whatever the app sends and however many requests race;
 *  - with "requires a rewarded ad" on, the coins are credited only when the ad network's
 *    verified callback arrives (RewardedClaimService); closing the ad credits nothing.
 * "Don't show again today" is a separate record: it hides the popup for the day and claims
 * nothing.
 */
class DailyRewardService
{
    public function __construct(
        private readonly AdsConfig $ads,
        private readonly QualifyingDeposits $deposits,
        private readonly WalletService $wallet,
    ) {}

    public function today(): string
    {
        return CarbonImmutable::now((string) config('minimax.daily_reward_timezone'))->toDateString();
    }

    public function campaignActive(): bool
    {
        if (! config('minimax.daily_reward_enabled')) {
            return false;
        }

        $today = $this->today();
        $starts = config('minimax.daily_reward_starts_on');
        $ends = config('minimax.daily_reward_ends_on');

        return ($starts === null || $today >= $starts) && ($ends === null || $today <= $ends);
    }

    public function requiresAd(): bool
    {
        return (bool) config('minimax.daily_reward_requires_ad');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $today = $this->today();
        $eligibility = $this->deposits->progress($user, (string) config('minimax.daily_reward_min_deposit'));
        $claim = DailyRewardClaim::where('user_id', $user->id)->whereDate('reward_day', $today)->first();
        $claimed = $claim?->status === 'credited';
        $dismissed = DailyRewardDismissal::where('user_id', $user->id)->whereDate('reward_day', $today)->exists();
        $adReady = ! $this->requiresAd() || $this->ads->isPurposeActive('daily_reward');
        $available = $this->campaignActive() && $eligibility['eligible'] && $adReady && ! $claimed;

        return [
            'day' => $today,
            'enabled' => $this->campaignActive(),
            'eligibility' => $eligibility,
            'requires_ad' => $this->requiresAd(),
            'ads_available' => $adReady,
            'claimed_today' => $claimed,
            'claimed_amount' => $claimed ? (string) $claim->amount : null,
            'dismissed_today' => $dismissed,
            'available' => $available,
            // The popup is offered only when it can actually be claimed and was not hidden for today.
            'show_popup' => $available && ! $dismissed,
            'range' => [
                'min' => (string) config('minimax.daily_reward_min_coins'),
                'max' => (string) config('minimax.daily_reward_max_coins'),
            ],
            'title' => (string) config('minimax.daily_reward_title'),
            'description' => (string) config('minimax.daily_reward_description'),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function assertCanClaim(User $user): void
    {
        $status = $this->status($user);
        $fail = fn (string $message) => throw ValidationException::withMessages(['daily_reward' => $message]);

        if (! $status['enabled']) {
            $fail('The daily reward isn\'t running right now.');
        }
        if ($status['claimed_today']) {
            $fail('You\'ve already claimed today\'s reward. Come back tomorrow.');
        }
        if (! $status['eligibility']['eligible']) {
            $fail('The daily reward unlocks after your successful deposits reach the minimum.');
        }
        if (! $status['ads_available']) {
            $fail('Rewarded ads aren\'t available right now. Try again later.');
        }
    }

    /**
     * Today's claim row, created with its server-drawn amount on first use.
     *
     * @throws ValidationException
     */
    public function reserveToday(User $user): DailyRewardClaim
    {
        $today = $this->today();

        try {
            $claim = DailyRewardClaim::firstOrCreate(
                ['user_id' => $user->id, 'reward_day' => $today],
                ['amount' => $this->drawAmount(), 'status' => 'reserved'],
            );
        } catch (UniqueConstraintViolationException) {
            $claim = DailyRewardClaim::where('user_id', $user->id)->whereDate('reward_day', $today)->firstOrFail();
        }

        if ($claim->status === 'credited') {
            throw ValidationException::withMessages(['daily_reward' => 'You\'ve already claimed today\'s reward. Come back tomorrow.']);
        }

        return $claim;
    }

    /**
     * Credits a reserved claim exactly once. Returns false when it was already credited.
     */
    public function credit(DailyRewardClaim $claim): bool
    {
        return DB::transaction(function () use ($claim) {
            /** @var DailyRewardClaim $locked */
            $locked = DailyRewardClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($locked->status === 'credited') {
                return false;
            }

            $entry = $this->wallet->creditReward(
                $locked->user,
                (string) $locked->amount,
                'daily_login_reward',
                "daily_reward:{$locked->id}",
                $locked,
                'Daily login reward '.$locked->reward_day->toDateString(),
            );

            $locked->update(['status' => 'credited', 'wallet_ledger_entry_id' => $entry->id, 'credited_at' => now()]);

            return true;
        });
    }

    /**
     * For the Admin setting that does not require an ad: claim and credit in one step.
     *
     * @throws ValidationException
     */
    public function claimWithoutAd(User $user): DailyRewardClaim
    {
        if ($this->requiresAd()) {
            throw ValidationException::withMessages(['daily_reward' => 'Watch the ad to claim today\'s reward.']);
        }

        $this->assertCanClaim($user);
        $claim = $this->reserveToday($user);
        $this->credit($claim);

        return $claim->fresh();
    }

    public function dismissToday(User $user): void
    {
        try {
            DailyRewardDismissal::firstOrCreate(['user_id' => $user->id, 'reward_day' => $this->today()]);
        } catch (UniqueConstraintViolationException) {
            // Already hidden for today.
        }
    }

    /** A random amount in the Admin range, to the paisa-equivalent 0.01 coin, drawn by the server. */
    private function drawAmount(): string
    {
        $min = (int) round((float) config('minimax.daily_reward_min_coins') * 100);
        $max = (int) round((float) config('minimax.daily_reward_max_coins') * 100);

        return number_format(random_int(min($min, $max), max($min, $max)) / 100, 2, '.', '');
    }
}
