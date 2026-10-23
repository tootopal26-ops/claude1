<?php

namespace Tests\Feature\Api;

use App\Models\DepositOrder;
use App\Models\Game;
use App\Models\LeaderboardEntry;
use App\Models\LeaderboardPeriod;
use App\Models\LeaderboardPrizeRule;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentEntryPlayer;
use App\Models\TournamentResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The read contracts the redesigned app screens are built on: tournament tabs and their
 * counts, per-game upcoming counts, finished-tournament standings, profile figures and
 * the leaderboard's top 100 with prizes.
 */
class AppContractsTest extends TestCase
{
    use RefreshDatabase;

    private function player(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function publishResult(Tournament $tournament, User $user, int $rank, int $kills, string $prize, array $names = ['Ace']): TournamentResult
    {
        $entry = TournamentEntry::create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'entry_fee_paid' => 0, 'status' => 'confirmed']);

        foreach ($names as $index => $name) {
            TournamentEntryPlayer::create(['tournament_entry_id' => $entry->id, 'slot_number' => $index + 1, 'in_game_name' => $name]);
        }

        return TournamentResult::create([
            'tournament_id' => $tournament->id,
            'tournament_entry_id' => $entry->id,
            'revision_number' => 1,
            'rank' => $rank,
            'kills' => $kills,
            'prize_awarded' => $prize,
            'submitted_by_staff_id' => $this->staffId(),
        ]);
    }

    private function staffId(): int
    {
        return $this->staff ??= StaffUser::factory()->superAdmin()->create()->id;
    }

    private ?int $staff = null;

    public function test_tournaments_filter_by_tab_and_report_every_tab_count_for_the_same_game(): void
    {
        $this->player();
        $game = Game::factory()->create();
        $other = Game::factory()->create();
        Tournament::factory()->count(2)->create(['game_id' => $game->id, 'status' => 'upcoming']);
        Tournament::factory()->create(['game_id' => $game->id, 'status' => 'ongoing']);
        Tournament::factory()->create(['game_id' => $game->id, 'status' => 'completed']);
        Tournament::factory()->create(['game_id' => $game->id, 'status' => 'cancelled']);
        Tournament::factory()->count(3)->create(['game_id' => $other->id, 'status' => 'upcoming']);

        $this->getJson("/api/v1/tournaments?game_id={$game->id}&status=upcoming")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.counts', ['upcoming' => 2, 'ongoing' => 1, 'completed' => 2]);

        $completed = $this->getJson("/api/v1/tournaments?game_id={$game->id}&status=completed")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['completed', 'cancelled'], array_column($completed, 'status'), 'cancelled tournaments sit in the Completed tab, labelled as cancelled');

        $this->getJson('/api/v1/tournaments?status=upcoming')->assertJsonCount(5, 'data')->assertJsonPath('meta.counts.upcoming', 5);
        $this->getJson('/api/v1/tournaments?status=bogus')->assertStatus(422);
    }

    public function test_a_tournament_carries_its_game_artwork_and_whether_joining_is_open(): void
    {
        $this->player();
        $open = Tournament::factory()->create(['scheduled_start_at' => now()->addHour()]);
        $full = Tournament::factory()->create(['total_slots' => 1, 'scheduled_start_at' => now()->addHour()]);
        TournamentEntry::create(['tournament_id' => $full->id, 'user_id' => User::factory()->create()->id, 'entry_fee_paid' => 0, 'status' => 'confirmed']);
        $late = Tournament::factory()->create(['scheduled_start_at' => now()->subHour()]);

        $this->getJson("/api/v1/tournaments/{$open->id}")
            ->assertOk()
            ->assertJsonPath('data.joining_open', true)
            ->assertJsonPath('data.game.id', $open->game_id)
            ->assertJsonPath('data.game.title', $open->game->name);

        $this->getJson("/api/v1/tournaments/{$full->id}")->assertJsonPath('data.joining_open', false);
        $this->getJson("/api/v1/tournaments/{$late->id}")->assertJsonPath('data.joining_open', false);
    }

    public function test_each_game_reports_how_many_of_its_tournaments_are_upcoming(): void
    {
        $this->player();
        $game = Game::factory()->create(['is_active' => true, 'display_order' => 1]);
        $quiet = Game::factory()->create(['is_active' => true, 'display_order' => 2]);
        Tournament::factory()->count(3)->create(['game_id' => $game->id, 'status' => 'upcoming']);
        Tournament::factory()->create(['game_id' => $game->id, 'status' => 'completed']);
        Tournament::factory()->create(['game_id' => $quiet->id, 'status' => 'ongoing']);

        $games = collect($this->getJson('/api/v1/games')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(3, $games[$game->id]['upcoming_tournaments_count']);
        $this->assertSame(0, $games[$quiet->id]['upcoming_tournaments_count']);
    }

    public function test_finished_tournament_standings_list_only_current_results_best_rank_first(): void
    {
        $this->player();
        $tournament = Tournament::factory()->create(['status' => 'completed', 'mode' => 'duo']);
        $winner = User::factory()->create(['username' => 'winner_one']);
        $second = User::factory()->create(['username' => 'runner_up']);
        $this->publishResult($tournament, $second, 2, 3, '20.00', ['Bee', 'Cee']);
        $old = $this->publishResult($tournament, $winner, 2, 1, '5.00', ['Ace', 'Dee']);
        $new = TournamentResult::create([
            'tournament_id' => $tournament->id, 'tournament_entry_id' => $old->tournament_entry_id,
            'revision_number' => 2, 'rank' => 1, 'kills' => 9, 'prize_awarded' => '80.00', 'submitted_by_staff_id' => $this->staffId(),
        ]);
        $old->update(['superseded_by_id' => $new->id]);

        $this->getJson("/api/v1/tournaments/{$tournament->id}/results")
            ->assertOk()
            ->assertJsonPath('data.published', true)
            ->assertJsonCount(2, 'data.results')
            ->assertJsonPath('data.results.0.rank', 1)
            ->assertJsonPath('data.results.0.username', 'winner_one')
            ->assertJsonPath('data.results.0.kills', 9)
            ->assertJsonPath('data.results.0.prize_awarded', '80.00')
            ->assertJsonPath('data.results.0.players.1.in_game_name', 'Dee')
            ->assertJsonPath('data.results.1.username', 'runner_up');

        $pending = Tournament::factory()->create(['status' => 'ongoing']);
        $this->getJson("/api/v1/tournaments/{$pending->id}/results")->assertOk()->assertJsonPath('data.published', false)->assertJsonCount(0, 'data.results');
    }

    public function test_profile_figures_come_from_results_and_confirmed_deposits_only(): void
    {
        $user = $this->player();
        $first = Tournament::factory()->create(['status' => 'completed']);
        $second = Tournament::factory()->create(['status' => 'completed']);
        $this->publishResult($first, $user, 1, 7, '80.00');
        $superseded = $this->publishResult($second, $user, 3, 1, '10.00');
        $current = TournamentResult::create([
            'tournament_id' => $second->id, 'tournament_entry_id' => $superseded->tournament_entry_id,
            'revision_number' => 2, 'rank' => 2, 'kills' => 4, 'prize_awarded' => '30.50', 'submitted_by_staff_id' => $this->staffId(),
        ]);
        $superseded->update(['superseded_by_id' => $current->id]);
        $this->publishResult(Tournament::factory()->create(['status' => 'completed']), User::factory()->create(), 1, 1, '999.00');

        foreach ([['success', '500.00'], ['success', '250.00'], ['pending', '1000.00'], ['failed', '300.00']] as [$status, $amount]) {
            DepositOrder::create(['user_id' => $user->id, 'amount' => $amount, 'our_order_id' => (string) Str::uuid(), 'provider' => 'alpha_upi', 'status' => $status]);
        }

        $this->getJson('/api/v1/profile/stats')
            ->assertOk()
            ->assertJsonPath('data.total_earnings', '110.50')
            ->assertJsonPath('data.matches', 2)
            ->assertJsonPath('data.deposits_total', '750.00')
            ->assertJsonPath('data.deposits_count', 2);
    }

    public function test_the_leaderboard_lists_the_top_100_with_player_ids_and_each_ranks_prize(): void
    {
        $this->player();
        LeaderboardPrizeRule::where('period_type', 'daily')->delete();
        $rule = LeaderboardPrizeRule::create(['period_type' => 'daily', 'is_enabled' => true]);
        $rule->ranks()->createMany([['rank' => 1, 'coins' => 500], ['rank' => 2, 'coins' => 300], ['rank' => 3, 'coins' => 200], ['rank' => 4, 'coins' => 50]]);

        $period = LeaderboardPeriod::create(['type' => 'daily', 'period_start' => now()->startOfDay(), 'period_end' => now()->endOfDay(), 'status' => 'provisional']);
        $users = User::factory()->count(105)->create();
        foreach ($users as $index => $user) {
            LeaderboardEntry::create(['leaderboard_period_id' => $period->id, 'user_id' => $user->id, 'rank' => $index + 1, 'score' => 1000 - $index, 'total_kills' => 1, 'tournaments_played' => 1]);
        }

        $data = $this->getJson('/api/v1/leaderboards/daily')->assertOk()->json('data');

        $this->assertCount(100, $data['entries']);
        $this->assertSame($users[0]->id, $data['entries'][0]['user_id']);
        $this->assertSame(500, $data['entries'][0]['prize']);
        $this->assertSame(50, $data['entries'][3]['prize']);
        $this->assertNull($data['entries'][10]['prize']);
        $this->assertFalse($data['prizes_final']);

        // A finalized period shows what it froze, not the rule as it is now.
        $period->update(['status' => 'finalized', 'finalized_at' => now(), 'prize_snapshot' => [1 => 700]]);
        $rule->ranks()->where('rank', 1)->update(['coins' => 1]);
        $data = $this->getJson('/api/v1/leaderboards/daily')->assertOk()->json('data');
        $this->assertSame(700, $data['entries'][0]['prize']);
        $this->assertNull($data['entries'][1]['prize']);
        $this->assertTrue($data['prizes_final']);
    }
}
