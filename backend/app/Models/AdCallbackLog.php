<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every server-to-server callback received, with its outcome — the Event Verification Log.
 */
#[Fillable(['provider', 'event_id', 'outcome', 'signature_valid', 'rewarded_claim_id', 'payload', 'ip_address'])]
class AdCallbackLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RewardedClaim, $this>
     */
    public function rewardedClaim(): BelongsTo
    {
        return $this->belongsTo(RewardedClaim::class);
    }
}
