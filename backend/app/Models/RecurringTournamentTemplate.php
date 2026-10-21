<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'game_id', 'title', 'description', 'rules', 'mode', 'entry_fee', 'per_kill_reward',
    'prize_pool', 'total_slots', 'min_players', 'frequency', 'day_of_week', 'time_of_day',
    'generate_days_ahead', 'is_active', 'created_by_staff_id',
])]
class RecurringTournamentTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'entry_fee' => 'decimal:2',
            'per_kill_reward' => 'decimal:2',
            'prize_pool' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }

    /**
     * @return HasMany<Tournament, $this>
     */
    public function generatedTournaments(): HasMany
    {
        return $this->hasMany(Tournament::class, 'template_id');
    }

    public function dayOfWeekLabel(): ?string
    {
        if ($this->day_of_week === null) {
            return null;
        }

        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$this->day_of_week];
    }
}
