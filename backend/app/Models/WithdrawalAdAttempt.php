<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that the optional post-success placement was attempted for a paid withdrawal, so it never runs twice for the same one.
 */
#[Fillable(['withdrawal_request_id', 'user_id'])]
class WithdrawalAdAttempt extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<WithdrawalRequest, $this>
     */
    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }
}
