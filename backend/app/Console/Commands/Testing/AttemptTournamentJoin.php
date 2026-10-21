<?php

namespace App\Console\Commands\Testing;

use App\Domain\Tournament\ConfirmSlotBookingAction;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Testing-only helper: runs ConfirmSlotBookingAction for one user from a
 * fresh PHP process with its own DB connection, so a test harness can
 * spawn several of these at once and prove the row lock in the action
 * actually serializes concurrent joins against a real MySQL connection
 * (a single in-process PHPUnit run can't exercise that, since it only
 * ever holds one DB connection).
 */
#[Signature('app:attempt-tournament-join {tournament} {user} {inGameName}')]
#[Description('Attempt to join a tournament as a given user; prints JOINED or REJECTED')]
class AttemptTournamentJoin extends Command
{
    public function handle(ConfirmSlotBookingAction $action): int
    {
        $tournament = Tournament::findOrFail($this->argument('tournament'));
        $user = User::findOrFail($this->argument('user'));

        try {
            $action->execute($tournament, $user, [
                ['in_game_name' => $this->argument('inGameName'), 'uid' => null],
            ]);
            $this->line('JOINED');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->line('REJECTED: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
