<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable, append-only. Never update or delete a row here — a mistake
 * is corrected with a new, opposite-signed entry referencing the original,
 * never by editing history.
 */
#[Fillable([
    'wallet_id', 'user_id', 'type', 'balance', 'amount', 'balance_after',
    'reason', 'source_type', 'source_id', 'created_by_staff_id', 'idempotency_key',
])]
class WalletLedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
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
        return bccomp($this->amount, '0', 2) > 0;
    }
}
