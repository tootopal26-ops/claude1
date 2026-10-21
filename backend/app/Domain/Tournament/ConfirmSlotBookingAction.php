<?php

namespace App\Domain\Tournament;

use App\Models\AuditLog;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atomically books a single Solo slot for a free-entry tournament.
 *
 * This is the Phase 1 slice of the full booking engine described in the
 * project plan: Duo/Squad multi-position booking, paid-entry wallet
 * deduction (deposit-then-winnings priority), and the join-window/
 * credential-release timing rules all land in Phase 5. Until the wallet
 * exists, a tournament with a non-zero entry fee is deliberately refused
 * here rather than silently let through for free — see the guard below.
 */
class ConfirmSlotBookingAction
{
    /**
     * @throws ValidationException
     */
    public function execute(Tournament $tournament, User $user, string $inGameName, ?string $uid): TournamentEntry
    {
        return DB::transaction(function () use ($tournament, $user, $inGameName, $uid) {
            /** @var Tournament $locked */
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->mode !== 'solo') {
                throw ValidationException::withMessages([
                    'tournament' => 'Only Solo tournaments can be joined in this build.',
                ]);
            }

            if ($locked->status !== 'upcoming') {
                throw ValidationException::withMessages([
                    'tournament' => 'Joining is closed for this tournament.',
                ]);
            }

            if ((float) $locked->entry_fee > 0) {
                throw ValidationException::withMessages([
                    'tournament' => 'Paid-entry tournaments are not yet available.',
                ]);
            }

            if ($locked->entries()->where('user_id', $user->id)->where('status', 'confirmed')->exists()) {
                throw ValidationException::withMessages([
                    'tournament' => 'You have already joined this tournament.',
                ]);
            }

            $confirmedCount = $locked->entries()->where('status', 'confirmed')->count();

            if ($confirmedCount >= $locked->total_slots) {
                throw ValidationException::withMessages([
                    'tournament' => 'This tournament is full.',
                ]);
            }

            $entry = TournamentEntry::create([
                'tournament_id' => $locked->id,
                'user_id' => $user->id,
                'entry_fee_paid' => 0,
                'status' => 'confirmed',
            ]);

            $entry->players()->create([
                'slot_number' => 1,
                'in_game_name' => $inGameName,
                'uid' => $uid,
            ]);

            AuditLog::record('tournament.joined', $user, $entry, [
                'tournament_id' => $locked->id,
                'in_game_name' => $inGameName,
            ]);

            return $entry->load('players');
        });
    }
}
