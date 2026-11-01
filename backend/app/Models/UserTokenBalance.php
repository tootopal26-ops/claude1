<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cached spin-token balance for one player. token_ledger_entries is the source of
 * truth; this column exists only so reads don't have to sum the ledger every time — the
 * same relationship Wallet has to wallet_ledger_entries.
 */
#[Fillable(['user_id', 'balance'])]
class UserTokenBalance extends Model
{
    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
