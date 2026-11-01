<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One verified spin, recorded once — the winning number was drawn by the server
 * (SpinService) the moment the ad was verified, never by the app.
 */
#[Fillable(['user_id', 'rewarded_claim_id', 'winning_number', 'tokens_awarded', 'probabilities_snapshot', 'token_ledger_entry_id'])]
class SpinAttempt extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'winning_number' => 'integer',
            'tokens_awarded' => 'integer',
            'probabilities_snapshot' => 'array',
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
     * @return BelongsTo<RewardedClaim, $this>
     */
    public function rewardedClaim(): BelongsTo
    {
        return $this->belongsTo(RewardedClaim::class);
    }
}
