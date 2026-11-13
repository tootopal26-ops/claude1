<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credited first-deposit bonus — see App\Domain\Payments\DepositBonusService, the
 * only place a row here is ever created, always from DepositService's webhook-verified
 * payment confirmation.
 */
#[Fillable(['user_id', 'deposit_order_id', 'amount', 'wallet_ledger_entry_id'])]
class DepositBonusAward extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DepositOrder, $this>
     */
    public function depositOrder(): BelongsTo
    {
        return $this->belongsTo(DepositOrder::class);
    }
}
