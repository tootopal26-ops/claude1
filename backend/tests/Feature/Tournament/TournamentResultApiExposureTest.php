<?php

namespace Tests\Feature\Tournament;

use App\Domain\Tournament\SubmitTournamentResultsAction;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentResultApiExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_result_is_null_before_results_are_published(): void
    {
        $tournament = Tournament::factory()->create(['status' => 'ongoing']);
        $user = User::factory()->create();
        TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/tournaments/{$tournament->id}")
            ->assertOk()
            ->assertJsonPath('data.my_result', null);
    }

    public function test_my_result_reflects_the_joined_players_own_published_result(): void
    {
        $tournament = Tournament::factory()->create(['status' => 'ongoing', 'prize_pool' => 100]);
        $user = User::factory()->create();
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 2, 'kills' => 6, 'prize_awarded' => '30.00'],
        ], $staff);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/tournaments/{$tournament->id}")
            ->assertOk()
            ->assertJsonPath('data.my_result.rank', 2)
            ->assertJsonPath('data.my_result.kills', 6)
            ->assertJsonPath('data.my_result.prize_awarded', '30.00');
    }

    public function test_a_non_participant_sees_no_result_even_after_publish(): void
    {
        $tournament = Tournament::factory()->create(['status' => 'ongoing', 'prize_pool' => 100]);
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id]);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 6, 'prize_awarded' => '30.00'],
        ], $staff);

        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/tournaments/{$tournament->id}")
            ->assertOk()
            ->assertJsonPath('data.my_result', null);
    }
}
