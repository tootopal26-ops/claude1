<?php

namespace App\Console\Commands\Testing;

use App\Models\Game;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Testing-only helper: seeds a single-slot Solo tournament plus N users
 * into the connection configured in the environment it's invoked under.
 * Used by the concurrent-join feature test to set up real, cross-process
 * MySQL fixtures that multiple spawned PHP processes can race against.
 */
#[Signature('app:seed-concurrency-fixture {players=5}')]
#[Description('Seed a single-slot tournament and N candidate players for a concurrency test')]
class SeedConcurrencyFixture extends Command
{
    public function handle(): int
    {
        $game = Game::create(['name' => 'Concurrency Test Game', 'is_active' => true]);

        $tournament = Tournament::create([
            'game_id' => $game->id,
            'title' => 'Concurrency Test Tournament',
            'mode' => 'solo',
            'entry_fee' => 0,
            'total_slots' => 1,
            'scheduled_start_at' => now()->addDay(),
            'status' => 'upcoming',
        ]);

        $userIds = collect(range(1, (int) $this->argument('players')))
            ->map(fn () => User::factory()->create()->id)
            ->all();

        $this->line(json_encode([
            'tournament_id' => $tournament->id,
            'user_ids' => $userIds,
        ]));

        return self::SUCCESS;
    }
}
