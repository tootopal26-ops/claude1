<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One verified token-for-coins exchange, recorded once — the tier, the tokens debited and
 * the coins credited to the existing Deposit wallet, all from one atomic transaction
 * (TokenExchangeService::exchange) gated on the same verified rewarded ad as a spin.
 */
#[Fillable([
    'user_id', 'rewarded_claim_id', 'tier_index', 'tokens_required', 'coins_received',
    'bonus_percent', 'status', 'failure_reason', 'token_ledger_entry_id', 'wallet_ledger_entry_id',
])]
class TokenExchangeTransaction extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'tier_index' => 'integer',
            'tokens_required' => 'integer',
            'coins_received' => 'decimal:2',
            'bonus_percent' => 'decimal:2',
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
