<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ticket toward a free tournament's ticket requirement, bought by exactly one verified rewarded claim.
 */
#[Fillable(['tournament_id', 'user_id', 'rewarded_claim_id'])]
class TournamentAdTicket extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<RewardedClaim, $this>
     */
    public function rewardedClaim(): BelongsTo
    {
        return $this->belongsTo(RewardedClaim::class);
    }
}
