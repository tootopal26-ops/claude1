<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class TournamentJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_can_join_a_free_solo_tournament(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->create(['total_slots' => 48]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tournaments/{$tournament->id}/join", [
                'in_game_name' => 'ShadowStrike',
                'uid' => '123456789012',
            ]);

        $response->assertCreated()->assertJsonPath('data.in_game_name', 'ShadowStrike');

        $this->assertDatabaseHas('tournament_entries', [
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_a_player_cannot_join_the_same_tournament_twice(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->create(['total_slots' => 48]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tournaments/{$tournament->id}/join", ['in_game_name' => 'ShadowStrike'])
            ->assertCreated();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tournaments/{$tournament->id}/join", ['in_game_name' => 'ShadowStrike']);

        $response->assertStatus(422)->assertJsonValidationErrors('tournament');
    }

    public function test_joining_a_full_tournament_is_rejected(): void
    {
        $tournament = Tournament::factory()->create(['total_slots' => 1]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->actingAs($firstUser, 'sanctum')
            ->postJson("/api/v1/tournaments/{$tournament->id}/join", ['in_game_name' => 'First'])
            ->assertCreated();

        $response = $this->actingAs($secondUser, 'sanctum')
            ->postJson("/api/v1/tournaments/{$tournament->id}/join", ['in_game_name' => 'Second']);

        $response->assertStatus(422)->assertJsonValidationErrors('tournament');
        $this->assertSame(1, $tournament->fresh()->confirmedEntriesCount());
    }

    public function test_joining_a_paid_tournament_is_rejected_until_the_wallet_ships(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->paid(50)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tournaments/{$tournament->id}/join", ['in_game_name' => 'ShadowStrike']);

        $response->assertStatus(422)->assertJsonValidationErrors('tournament');
    }

    public function test_the_tournament_list_reports_accurate_joined_slot_counts(): void
    {
        $tournament = Tournament::factory()->create(['total_slots' => 10]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tournaments/{$tournament->id}/join", ['in_game_name' => 'ShadowStrike'])
            ->assertCreated();

        $response = $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/tournaments');

        $response->assertOk()->assertJsonFragment(['id' => $tournament->id, 'joined_slots' => 1, 'total_slots' => 10]);
    }

    /**
     * This is the one test in the suite that deliberately does NOT use the
     * sqlite in-memory connection PHPUnit otherwise runs under: an
     * in-process test can only ever hold a single DB connection, so it
     * can't actually exercise two transactions racing for the same row
     * lock. Instead it spawns several real, independent PHP processes
     * against the shared `minimax_testing` MySQL database (each gets its
     * own connection) and proves ConfirmSlotBookingAction's
     * lockForUpdate() lets exactly one of them win a single-slot
     * tournament, under genuine concurrency.
     */
    public function test_concurrent_joins_never_oversell_a_single_slot_tournament(): void
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to prepare the concurrency fixture.');
        }

        $env = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'minimax_testing'];

        $migrate = Process::env($env)->path(base_path())->run('php artisan migrate:fresh --no-interaction');
        $this->assertTrue($migrate->successful(), $migrate->errorOutput());

        $seed = Process::env($env)->path(base_path())->run('php artisan app:seed-concurrency-fixture 8');
        $this->assertTrue($seed->successful(), $seed->errorOutput());

        $fixture = json_decode(trim($seed->output()), true, flags: JSON_THROW_ON_ERROR);
        $tournamentId = $fixture['tournament_id'];
        $userIds = $fixture['user_ids'];

        $results = Process::pool(function ($pool) use ($env, $tournamentId, $userIds) {
            foreach ($userIds as $index => $userId) {
                $pool->as((string) $userId)
                    ->env($env)
                    ->path(base_path())
                    ->command("php artisan app:attempt-tournament-join {$tournamentId} {$userId} Player{$index}");
            }
        })->start()->wait();

        $joined = collect($results)->filter(fn ($result) => str_contains($result->output(), 'JOINED'));
        $rejected = collect($results)->filter(fn ($result) => str_contains($result->output(), 'REJECTED'));

        $this->assertCount(1, $joined, 'Exactly one concurrent join should win the single available slot.');
        $this->assertCount(count($userIds) - 1, $rejected);

        config(['database.connections.concurrency_check' => array_merge(
            config('database.connections.mysql'),
            ['database' => 'minimax_testing'],
        )]);
        DB::purge('concurrency_check');

        $confirmedCount = DB::connection('concurrency_check')
            ->table('tournament_entries')
            ->where('tournament_id', $tournamentId)
            ->where('status', 'confirmed')
            ->count();

        $this->assertSame(1, $confirmedCount, 'The tournament must never end up with more confirmed entries than slots.');
    }
}
