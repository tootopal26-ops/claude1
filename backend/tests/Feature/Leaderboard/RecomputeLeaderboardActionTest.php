<?php

namespace Tests\Feature\Leaderboard;

use App\Domain\Leaderboard\RecomputeLeaderboardAction;
use App\Models\LeaderboardEntry;
use App\Models\LeaderboardPeriod;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomputeLeaderboardActionTest extends TestCase
{
    use RefreshDatabase;

    private StaffUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = StaffUser::factory()->create();
    }

    private function makePeriod(array $overrides = []): LeaderboardPeriod
    {
        return LeaderboardPeriod::create(array_merge([
            'type' => 'daily',
            'period_start' => '2026-01-15 00:00:00',
            'period_end' => '2026-01-16 00:00:00',
            'status' => 'provisional',
        ], $overrides));
    }

    private function makeResult(User $user, string $scheduledStartAt, int $rank, int $kills, string $prize, bool $superseded = false): TournamentResult
    {
        $tournament = Tournament::factory()->create(['scheduled_start_at' => $scheduledStartAt]);
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        $result = TournamentResult::create([
            'tournament_id' => $tournament->id,
            'tournament_entry_id' => $entry->id,
            'revision_number' => 1,
            'rank' => $rank,
            'kills' => $kills,
            'prize_awarded' => $prize,
            'submitted_by_staff_id' => $this->staff->id,
        ]);

        if ($superseded) {
            // Simulate a correction: this row is no longer current.
            $newer = $result->replicate();
            $newer->revision_number = 2;
            $newer->save();
            $result->superseded_by_id = $newer->id;
            $result->save();
        }

        return $result;
    }

    public function test_it_aggregates_prize_and_kills_per_user_within_the_period(): void
    {
        $period = $this->makePeriod();
        $user = User::factory()->create();

        $this->makeResult($user, '2026-01-15 10:00:00', 1, 5, '50.00');
        $this->makeResult($user, '2026-01-15 18:00:00', 2, 3, '20.00');

        app(RecomputeLeaderboardAction::class)->execute($period);

        $entry = LeaderboardEntry::where('leaderboard_period_id', $period->id)->where('user_id', $user->id)->first();
        $this->assertSame('70.00', $entry->score);
        $this->assertSame(8, $entry->total_kills);
        $this->assertSame(2, $entry->tournaments_played);
        $this->assertSame(1, $entry->rank);
    }

    public function test_results_outside_the_period_bounds_are_excluded(): void
    {
        $period = $this->makePeriod();
        $user = User::factory()->create();

        $this->makeResult($user, '2026-01-14 23:59:59', 1, 5, '50.00'); // before
        $this->makeResult($user, '2026-01-16 00:00:00', 1, 5, '50.00'); // at/after the exclusive end

        app(RecomputeLeaderboardAction::class)->execute($period);

        $this->assertSame(0, LeaderboardEntry::where('leaderboard_period_id', $period->id)->count());
    }

    public function test_superseded_results_are_excluded_in_favor_of_the_current_revision(): void
    {
        $period = $this->makePeriod();
        $user = User::factory()->create();

        $this->makeResult($user, '2026-01-15 10:00:00', 5, 1, '10.00', superseded: true);

        app(RecomputeLeaderboardAction::class)->execute($period);

        $entry = LeaderboardEntry::where('leaderboard_period_id', $period->id)->where('user_id', $user->id)->first();
        // The superseded row awarded 10.00; the current (replicated) row also
        // carries 10.00 in this fixture, so the aggregate reflects only the
        // one current row, not both.
        $this->assertSame('10.00', $entry->score);
    }

    public function test_ties_are_broken_deterministically_by_kills_then_user_id(): void
    {
        $period = $this->makePeriod();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $lowerId = min($userA->id, $userB->id);

        // Equal prize, equal kills — the tie-break falls through to user_id.
        $this->makeResult($userA, '2026-01-15 10:00:00', 1, 5, '50.00');
        $this->makeResult($userB, '2026-01-15 11:00:00', 1, 5, '50.00');

        app(RecomputeLeaderboardAction::class)->execute($period);

        $first = LeaderboardEntry::where('leaderboard_period_id', $period->id)->where('rank', 1)->first();
        $this->assertSame($lowerId, $first->user_id);
    }

    public function test_recomputing_is_idempotent_and_replaces_rather_than_duplicates(): void
    {
        $period = $this->makePeriod();
        $user = User::factory()->create();
        $this->makeResult($user, '2026-01-15 10:00:00', 1, 5, '50.00');

        $action = app(RecomputeLeaderboardAction::class);
        $action->execute($period);
        $action->execute($period);

        $this->assertSame(1, LeaderboardEntry::where('leaderboard_period_id', $period->id)->count());
    }

    public function test_recomputing_a_finalized_period_is_a_no_op(): void
    {
        $period = $this->makePeriod(['status' => 'finalized', 'finalized_at' => now()]);
        $user = User::factory()->create();
        $this->makeResult($user, '2026-01-15 10:00:00', 1, 5, '50.00');

        app(RecomputeLeaderboardAction::class)->execute($period);

        $this->assertSame(0, LeaderboardEntry::where('leaderboard_period_id', $period->id)->count());
    }
}
