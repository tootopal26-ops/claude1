<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed referral relationship: the new player who entered a valid code, the
 * referrer whose code it was, and the reward each received — see
 * App\Domain\Referrals\ReferralService, the only place a row here is ever created.
 */
#[Fillable(['referrer_user_id', 'referred_user_id', 'referral_code_used', 'new_player_reward', 'referrer_reward', 'status', 'new_player_wallet_ledger_entry_id', 'referrer_wallet_ledger_entry_id'])]
class Referral extends Model
{
    protected function casts(): array
    {
        return [
            'new_player_reward' => 'decimal:2',
            'referrer_reward' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
