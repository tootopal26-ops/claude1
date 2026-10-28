<?php

namespace Tests\Feature\Tournament;

use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JoinedTournamentsFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_joined_lists_only_the_players_confirmed_entries_with_matching_counts(): void
    {
        $player = User::factory()->create();
        $mine = Tournament::factory()->create(['status' => 'upcoming']);
        $mineDone = Tournament::factory()->create(['status' => 'completed']);
        $cancelledEntry = Tournament::factory()->create(['status' => 'upcoming']);
        Tournament::factory()->create(['status' => 'upcoming']);

        TournamentEntry::factory()->create(['tournament_id' => $mine->id, 'user_id' => $player->id]);
        TournamentEntry::factory()->create(['tournament_id' => $mineDone->id, 'user_id' => $player->id]);
        TournamentEntry::factory()->create(['tournament_id' => $cancelledEntry->id, 'user_id' => $player->id, 'status' => 'cancelled']);
        TournamentEntry::factory()->create(['tournament_id' => $mine->id]);

        Sanctum::actingAs($player);

        $response = $this->getJson('/api/v1/tournaments?joined=1')->assertOk();
        $this->assertEqualsCanonicalizing([$mine->id, $mineDone->id], collect($response->json('data'))->pluck('id')->all());
        $response->assertJsonPath('meta.counts', ['upcoming' => 1, 'ongoing' => 0, 'completed' => 1]);

        $this->getJson('/api/v1/tournaments?joined=1&status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mineDone->id);

        // Without the filter nothing changes.
        $this->getJson('/api/v1/tournaments')->assertOk()->assertJsonPath('meta.counts.upcoming', 3);
    }
}
