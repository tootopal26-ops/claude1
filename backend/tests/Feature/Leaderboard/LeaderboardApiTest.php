<?php

namespace Tests\Feature\Leaderboard;

use App\Domain\Leaderboard\EnsureLeaderboardPeriodsExistAction;
use App\Domain\Leaderboard\RecomputeLeaderboardAction;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaderboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_unknown_type_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/leaderboards/yearly')->assertNotFound();
    }

    public function test_a_brand_new_installation_lazily_creates_the_period_and_returns_an_empty_board(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/leaderboards/daily');

        $response->assertOk()
            ->assertJsonPath('data.type', 'daily')
            ->assertJsonPath('data.status', 'provisional')
            ->assertJsonPath('data.entries', []);
    }

    public function test_entries_and_the_authenticated_players_own_rank_are_returned(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');
        $staff = StaffUser::factory()->create();
        $user = User::factory()->create(['username' => 'topscorer']);

        $tournament = Tournament::factory()->create(['scheduled_start_at' => now()]);
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
        TournamentResult::create([
            'tournament_id' => $tournament->id,
            'tournament_entry_id' => $entry->id,
            'revision_number' => 1,
            'rank' => 1,
            'kills' => 10,
            'prize_awarded' => '75.00',
            'submitted_by_staff_id' => $staff->id,
        ]);

        $period = app(EnsureLeaderboardPeriodsExistAction::class)->execute()['daily'];
        app(RecomputeLeaderboardAction::class)->execute($period);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/leaderboards/daily');

        $response->assertOk()
            ->assertJsonPath('data.entries.0.username', 'topscorer')
            ->assertJsonPath('data.entries.0.score', '75.00')
            ->assertJsonPath('data.my_entry.rank', 1);
    }
}
