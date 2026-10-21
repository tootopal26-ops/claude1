<?php

namespace App\Models;

use Database\Factories\TournamentEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tournament_id', 'user_id', 'entry_fee_paid', 'status'])]
class TournamentEntry extends Model
{
    /** @use HasFactory<TournamentEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'entry_fee_paid' => 'decimal:2',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TournamentEntryPlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(TournamentEntryPlayer::class);
    }
}
