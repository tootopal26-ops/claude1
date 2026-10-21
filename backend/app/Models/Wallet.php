<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'deposit_balance', 'eligible_winnings_balance'])]
class Wallet extends Model
{
    protected function casts(): array
    {
        return [
            'deposit_balance' => 'decimal:2',
            'eligible_winnings_balance' => 'decimal:2',
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
     * @return HasMany<WalletLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function totalBalance(): string
    {
        return bcadd($this->deposit_balance, $this->eligible_winnings_balance, 2);
    }
}
