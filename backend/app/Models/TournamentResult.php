<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tournament_id', 'tournament_entry_id', 'revision_number', 'rank', 'kills',
    'prize_awarded', 'submitted_by_staff_id', 'superseded_by_id',
])]
class TournamentResult extends Model
{
    protected function casts(): array
    {
        return [
            'prize_awarded' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<TournamentEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'tournament_entry_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function submittedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'submitted_by_staff_id');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_by_id === null;
    }
}
