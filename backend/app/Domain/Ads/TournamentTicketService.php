<?php

namespace App\Domain\Ads;

use App\Models\Tournament;
use App\Models\TournamentAdTicket;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Rewarded-ad tickets for free tournaments. An Admin sets how many a free tournament needs
 * (0 = none); a player earns one per verified rewarded ad started for that tournament, and
 * the join action refuses the player until they hold enough. Tickets are counted on the
 * server only, are tied to one tournament and one verified claim each, never exceed the
 * requirement, and never register a player on their own — joining is a separate action
 * that re-checks every tournament rule. Paid tournaments never use tickets.
 */
class TournamentTicketService
{
    public function required(Tournament $tournament): int
    {
        return $tournament->isFree() ? (int) $tournament->required_ad_tickets : 0;
    }

    public function earned(Tournament $tournament, User $user): int
    {
        return TournamentAdTicket::where('tournament_id', $tournament->id)->where('user_id', $user->id)->count();
    }

    public function remaining(Tournament $tournament, User $user): int
    {
        return max(0, $this->required($tournament) - $this->earned($tournament, $user));
    }

    /**
     * May a new ticket ad start for this player and tournament?
     *
     * @throws ValidationException
     */
    public function assertCanEarn(Tournament $tournament, User $user): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['tournament' => $message]);

        if ($this->required($tournament) === 0) {
            $fail('This tournament doesn\'t need tickets.');
        }
        if (! $tournament->isJoiningOpen()) {
            $fail('Joining is closed for this tournament.');
        }
        if ($tournament->entries()->where('user_id', $user->id)->where('status', 'confirmed')->exists()) {
            $fail('You have already joined this tournament.');
        }
        if ($this->remaining($tournament, $user) === 0) {
            $fail('You already have every ticket this tournament needs.');
        }
    }

    /**
     * Used by the join action, inside its locked transaction.
     *
     * @throws ValidationException
     */
    public function assertSatisfied(Tournament $tournament, User $user): void
    {
        $remaining = $this->remaining($tournament, $user);

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'tournament' => $remaining === 1
                    ? 'Watch 1 more ad to unlock this tournament.'
                    : "Watch {$remaining} more ads to unlock this tournament.",
            ]);
        }
    }
}
