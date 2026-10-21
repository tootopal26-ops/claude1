<?php

namespace App\Domain\Tournament;

use App\Domain\Notifications\NotificationService;
use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atomically books one team slot (Solo/Duo/Squad) on a tournament,
 * deducting the entry fee (deposit balance first, then eligible winnings)
 * when the tournament isn't free.
 */
class ConfirmSlotBookingAction
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly WalletService $wallet,
    ) {}

    /**
     * @param  list<array{in_game_name: string, uid: ?string}>  $players
     *
     * @throws ValidationException
     * @throws InsufficientWalletBalanceException
     */
    public function execute(Tournament $tournament, User $user, array $players): TournamentEntry
    {
        $entry = DB::transaction(function () use ($tournament, $user, $players) {
            /** @var Tournament $locked */
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status !== 'upcoming') {
                throw ValidationException::withMessages([
                    'tournament' => 'Joining is closed for this tournament.',
                ]);
            }

            if ($locked->credentials_released_at !== null) {
                throw ValidationException::withMessages([
                    'tournament' => 'Joining is closed for this tournament.',
                ]);
            }

            $joinWindowCloses = $locked->scheduled_start_at->copy()
                ->addMinutes((int) config('minimax.tournament_join_window_minutes'));

            if (now()->greaterThanOrEqualTo($joinWindowCloses)) {
                throw ValidationException::withMessages([
                    'tournament' => 'Joining is closed for this tournament.',
                ]);
            }

            if (count($players) !== $locked->requiredPlayerCount()) {
                throw ValidationException::withMessages([
                    'players' => "This {$locked->mode} tournament requires exactly {$locked->requiredPlayerCount()} player(s) per booking.",
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
                'entry_fee_paid' => $locked->entry_fee,
                'status' => 'confirmed',
            ]);

            foreach ($players as $index => $player) {
                $entry->players()->create([
                    'slot_number' => $index + 1,
                    'in_game_name' => $player['in_game_name'],
                    'uid' => $player['uid'],
                ]);
            }

            if (! $locked->isFree()) {
                try {
                    $this->wallet->debitForEntryFee(
                        $user,
                        (string) $locked->entry_fee,
                        "tournament.entry_fee:{$entry->id}",
                        $entry,
                    );
                } catch (InsufficientWalletBalanceException) {
                    throw ValidationException::withMessages([
                        'tournament' => 'Insufficient wallet balance to pay this tournament\'s entry fee.',
                    ]);
                }
            }

            AuditLog::record('tournament.joined', $user, $entry, [
                'tournament_id' => $locked->id,
                'entry_fee_paid' => (string) $locked->entry_fee,
                'players' => array_column($players, 'in_game_name'),
            ]);

            return $entry->load('players');
        });

        $this->notifications->notify(
            recipients: [$user],
            type: 'tournament.joined',
            title: 'You\'re in!',
            body: "You've successfully joined {$tournament->title}.",
            data: ['tournament_id' => (string) $tournament->id, 'deep_link' => "tournament/{$tournament->id}"],
            idempotencyKey: "tournament.joined:{$entry->id}",
        );

        return $entry;
    }
}
