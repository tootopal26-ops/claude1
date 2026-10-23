<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'redeem_campaign_id', 'submitted_code', 'result', 'ip_address'])]
class RedeemAttempt extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<RedeemCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RedeemCampaign::class, 'redeem_campaign_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
