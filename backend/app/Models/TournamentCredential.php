<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tournament_id', 'room_id', 'room_password', 'released_by_staff_id'])]
class TournamentCredential extends Model
{
    protected function casts(): array
    {
        return [
            'room_id' => 'encrypted',
            'room_password' => 'encrypted',
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
     * @return BelongsTo<StaffUser, $this>
     */
    public function releasedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'released_by_staff_id');
    }
}
