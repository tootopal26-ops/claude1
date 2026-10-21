<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['leaderboard_period_id', 'user_id', 'rank', 'amount'])]
class LeaderboardRewardPayout extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<LeaderboardPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(LeaderboardPeriod::class, 'leaderboard_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
