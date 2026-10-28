<?php

namespace App\Domain\Ads;

use App\Models\User;
use App\Models\WithdrawalAdAttempt;
use App\Models\WithdrawalRequest;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The optional interstitial after a withdrawal succeeds. It may be attempted only for the
 * player's own withdrawal, only once the server has marked it paid (never while pending,
 * approved, processing, rejected or failed), only if the placement is live, and only once
 * per withdrawal and only shortly after the payout — the attempt is recorded under a unique key
 * before the app shows anything.
 * The payout itself never depends on an ad.
 */
class WithdrawalAdService
{
    /** A payout older than this no longer gets the post-success placement (it isn't "just paid"). */
    public const int RECENT_HOURS = 72;

    public function __construct(private readonly AdsConfig $ads) {}

    /** True when the app may show the placement now (and it has been recorded as attempted). */
    public function attempt(User $user, WithdrawalRequest $withdrawal): bool
    {
        if ($withdrawal->user_id !== $user->id || $withdrawal->status !== 'paid' || ! $this->ads->isPlacementActive('withdrawal_success')) {
            return false;
        }

        if ($withdrawal->settled_at === null || $withdrawal->settled_at->lt(now()->subHours(self::RECENT_HOURS))) {
            return false;
        }

        try {
            WithdrawalAdAttempt::create(['withdrawal_request_id' => $withdrawal->id, 'user_id' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
