<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rewarded-ad opportunity the server opened for a player and purpose. Only a verified provider callback for its token completes it.
 */
#[Fillable(['token', 'user_id', 'purpose', 'placement_key', 'tournament_id', 'daily_reward_claim_id', 'redeem_reservation_id', 'ad_reward_campaign_id', 'status', 'expires_at', 'provider', 'provider_event_id', 'verification_method', 'counts_for_earning', 'reward_coins', 'wallet_ledger_entry_id', 'failure_reason', 'verified_at', 'ip_address'])]
class RewardedClaim extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'counts_for_earning' => 'boolean',
            'reward_coins' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<DailyRewardClaim, $this>
     */
    public function dailyRewardClaim(): BelongsTo
    {
        return $this->belongsTo(DailyRewardClaim::class);
    }

    /**
     * @return BelongsTo<RedeemReservation, $this>
     */
    public function redeemReservation(): BelongsTo
    {
        return $this->belongsTo(RedeemReservation::class);
    }

    /**
     * @return BelongsTo<AdRewardCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdRewardCampaign::class, 'ad_reward_campaign_id');
    }
}
