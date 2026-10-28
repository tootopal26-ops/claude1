<?php

namespace App\Models;

use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'game_id', 'title', 'description', 'rules', 'image_path', 'mode',
    'entry_fee', 'required_ad_tickets', 'per_kill_reward', 'prize_pool', 'total_slots', 'min_players',
    'scheduled_start_at', 'status', 'template_id',
])]
class Tournament extends Model
{
    /** @use HasFactory<TournamentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'entry_fee' => 'decimal:2',
            'required_ad_tickets' => 'integer',
            'per_kill_reward' => 'decimal:2',
            'prize_pool' => 'decimal:2',
            'scheduled_start_at' => 'datetime',
            'credentials_released_at' => 'datetime',
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
     * @return HasMany<TournamentEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    public function confirmedEntriesCount(): int
    {
        return $this->entries()->where('status', 'confirmed')->count();
    }

    public function isFree(): bool
    {
        return (float) $this->entry_fee === 0.0;
    }

    /**
     * Joining is open while the tournament is upcoming, its room details are not released,
     * the join window (scheduled start + the configured minutes) has not closed and a slot
     * is free. Pass the confirmed-entry count when it is already loaded.
     */
    public function isJoiningOpen(?int $confirmedEntries = null): bool
    {
        $joinWindowCloses = $this->scheduled_start_at->copy()->addMinutes((int) config('minimax.tournament_join_window_minutes'));

        return $this->status === 'upcoming'
            && $this->credentials_released_at === null
            && now()->lessThan($joinWindowCloses)
            && ($confirmedEntries ?? $this->confirmedEntriesCount()) < $this->total_slots;
    }

    /**
     * How many individual players a single booking (one tournament_entries
     * row) must register, by mode. total_slots counts team bookings, not
     * individual players, in every mode.
     */
    public function requiredPlayerCount(): int
    {
        return match ($this->mode) {
            'solo' => 1,
            'duo' => 2,
            'squad' => 4,
        };
    }

    /**
     * @return HasOne<TournamentCredential, $this>
     */
    public function credential(): HasOne
    {
        return $this->hasOne(TournamentCredential::class);
    }

    /**
     * @return BelongsTo<RecurringTournamentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringTournamentTemplate::class, 'template_id');
    }
}
