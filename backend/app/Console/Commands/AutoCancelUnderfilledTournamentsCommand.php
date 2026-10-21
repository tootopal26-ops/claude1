<?php

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationService;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\Tournament;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auto-cancels a tournament that still hasn't reached its configured
 * min_players this many minutes after scheduled_start_at, and refunds
 * every confirmed entry's paid fee exactly once via WalletService's
 * idempotent refundExact.
 *
 * Skips any tournament whose credentials have already been released —
 * that's staff deciding to run the match regardless of headcount, which
 * overrides this check (see ReleaseCredentialsAction). Matches on status
 * upcoming OR ongoing so this doesn't depend on
 * CloseTournamentJoiningWindowCommand having already run.
 *
 * Idempotent: a tournament is only ever touched while its status is still
 * upcoming/ongoing, so a tournament this has already cancelled is skipped
 * on any rerun.
 */
#[Signature('tournaments:auto-cancel-underfilled')]
#[Description('Cancel and refund tournaments that missed their minimum player count')]
class AutoCancelUnderfilledTournamentsCommand extends Command
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $graceMinutes = (int) config('minimax.tournament_min_players_grace_minutes');
        $cutoff = now()->subMinutes($graceMinutes);

        $candidateIds = Tournament::query()
            ->whereIn('status', ['upcoming', 'ongoing'])
            ->whereNotNull('min_players')
            ->whereNull('credentials_released_at')
            ->where('scheduled_start_at', '<=', $cutoff)
            ->pluck('id');

        $cancelled = 0;

        foreach ($candidateIds as $tournamentId) {
            if ($this->cancelIfStillUnderfilled($tournamentId, $graceMinutes)) {
                $cancelled++;
            }
        }

        $this->line("Auto-cancelled {$cancelled} underfilled tournament(s).");

        return self::SUCCESS;
    }

    private function cancelIfStillUnderfilled(int $tournamentId, int $graceMinutes): bool
    {
        $refundableEntries = DB::transaction(function () use ($tournamentId, $graceMinutes) {
            /** @var Tournament|null $tournament */
            $tournament = Tournament::query()->lockForUpdate()->find($tournamentId);

            if ($tournament === null
                || ! in_array($tournament->status, ['upcoming', 'ongoing'], true)
                || $tournament->min_players === null
                || $tournament->credentials_released_at !== null) {
                return null;
            }

            $graceEndsAt = $tournament->scheduled_start_at->copy()->addMinutes($graceMinutes);

            if (now()->lessThan($graceEndsAt)) {
                return null;
            }

            $confirmedEntries = $tournament->entries()->where('status', 'confirmed')->with('user')->get();

            if ($confirmedEntries->count() >= $tournament->min_players) {
                return null;
            }

            $tournament->status = 'cancelled';
            $tournament->cancellation_reason = 'min_players_not_met';
            $tournament->save();

            AuditLog::record('tournament.auto_cancelled', null, $tournament, [
                'reason' => 'min_players_not_met',
                'confirmed_count' => $confirmedEntries->count(),
                'min_players' => $tournament->min_players,
            ]);

            foreach ($confirmedEntries as $entry) {
                $hasFee = (float) $entry->entry_fee_paid > 0;

                if ($hasFee) {
                    $this->wallet->refundExact(
                        $entry->user,
                        "tournament.entry_fee:{$entry->id}",
                        "tournament.refund:{$entry->id}",
                        $entry,
                        'Tournament cancelled — minimum players not met.',
                    );
                }

                $entry->status = $hasFee ? 'refunded' : 'cancelled';
                $entry->save();
            }

            return $confirmedEntries;
        });

        if ($refundableEntries === null) {
            return false;
        }

        if ($refundableEntries->isNotEmpty()) {
            $tournament = Tournament::find($tournamentId);

            $this->notifications->notify(
                recipients: $refundableEntries->pluck('user'),
                type: 'tournament.cancelled',
                title: 'Tournament cancelled',
                body: "{$tournament->title} was cancelled because the minimum player count wasn't met. Any entry fee you paid has been refunded.",
                data: ['tournament_id' => (string) $tournamentId],
                idempotencyKey: "tournament.cancelled:{$tournamentId}",
            );
        }

        return true;
    }
}
