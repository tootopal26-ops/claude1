<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Don't show again today": hides the daily reward popup for one day without claiming anything.
 */
#[Fillable(['user_id', 'reward_day'])]
class DailyRewardDismissal extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'reward_day' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
