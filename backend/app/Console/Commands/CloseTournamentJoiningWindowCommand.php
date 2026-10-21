<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Tournament;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Closes joining on any upcoming tournament once its join window has
 * elapsed (scheduled_start_at + tournament_join_window_minutes), by
 * flipping its status to "ongoing". ConfirmSlotBookingAction enforces the
 * same deadline inline for immediate correctness; this command only keeps
 * the stored status in sync so the rest of the app (lists, the Admin
 * panel) reflects it without recomputing the deadline on every read.
 *
 * Safe to run more than once a minute or to rerun after a crash: a
 * tournament is only ever touched while its status is still "upcoming",
 * so a tournament this has already closed is simply skipped next time.
 */
#[Signature('tournaments:close-joining-window')]
#[Description('Flip upcoming tournaments past their join window to ongoing')]
class CloseTournamentJoiningWindowCommand extends Command
{
    public function handle(): int
    {
        $windowMinutes = (int) config('minimax.tournament_join_window_minutes');
        $cutoff = now()->subMinutes($windowMinutes);

        $candidateIds = Tournament::query()
            ->where('status', 'upcoming')
            ->where('scheduled_start_at', '<=', $cutoff)
            ->pluck('id');

        $closed = 0;

        foreach ($candidateIds as $tournamentId) {
            $closed += DB::transaction(function () use ($tournamentId, $windowMinutes) {
                $tournament = Tournament::query()->lockForUpdate()->find($tournamentId);

                if ($tournament === null || $tournament->status !== 'upcoming') {
                    return 0;
                }

                $joinWindowCloses = $tournament->scheduled_start_at->copy()->addMinutes($windowMinutes);

                if (now()->lessThan($joinWindowCloses)) {
                    return 0;
                }

                $tournament->status = 'ongoing';
                $tournament->save();

                AuditLog::record('tournament.join_window_closed', null, $tournament);

                return 1;
            });
        }

        $this->line("Closed joining on {$closed} tournament(s).");

        return self::SUCCESS;
    }
}
