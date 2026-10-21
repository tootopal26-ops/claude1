<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tournament_entry_id', 'slot_number', 'in_game_name', 'uid'])]
class TournamentEntryPlayer extends Model
{
    /**
     * @return BelongsTo<TournamentEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'tournament_entry_id');
    }
}
