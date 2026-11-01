<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable, append-only, exactly like WalletLedgerEntry — the source of truth for a
 * player's token balance. Never update or delete a row here; a mistake is corrected with a
 * new, opposite-signed entry, never by editing history.
 */
#[Fillable([
    'user_id', 'type', 'amount', 'balance_after', 'reason',
    'source_type', 'source_id', 'idempotency_key', 'created_by_staff_id',
])]
class TokenLedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }
}
