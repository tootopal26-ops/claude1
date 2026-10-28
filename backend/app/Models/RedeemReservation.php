<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Holds one place in a redeem campaign's capacity while the player watches the rewarded ad it requires. Expires if abandoned; never consumes the code on its own.
 */
#[Fillable(['redeem_campaign_id', 'user_id', 'status', 'expires_at'])]
class RedeemReservation extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

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
