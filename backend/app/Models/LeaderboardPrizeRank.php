<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['leaderboard_prize_rule_id', 'rank', 'coins'])]
class LeaderboardPrizeRank extends Model
{
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'coins' => 'integer',
        ];
    }
}
