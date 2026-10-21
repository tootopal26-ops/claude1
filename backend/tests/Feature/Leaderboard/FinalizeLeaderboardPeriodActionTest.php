<?php

namespace Tests\Feature\Leaderboard;

use App\Domain\Leaderboard\FinalizeLeaderboardPeriodAction;
use App\Domain\Leaderboard\RecomputeLeaderboardAction;
use App\Models\LeaderboardPeriod;
use App\Models\LeaderboardRewardPayout;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinalizeLeaderboardPeriodActionTest extends TestCase
{
    use RefreshDatabase;

    private StaffUser $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = StaffUser::factory()->create();
    }

    private function makePeriod(string $type = 'daily'): LeaderboardPeriod
    {
        return LeaderboardPeriod::create([
            'type' => $type,
            'period_start' => '2026-01-15 00:00:00',
            'period_end' => '2026-01-16 00:00:00',
            'status' => 'provisional',
        ]);
    }

    private function giveResult(User $user, int $rank, string $prize): void
    {
        $tournament = Tournament::factory()->create(['scheduled_start_at' => '2026-01-15 10:00:00']);
        $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

        TournamentResult::create([
            'tournament_id' => $tournament->id,
            'tournament_entry_id' => $entry->id,
            'revision_number' => 1,
            'rank' => $rank,
            'kills' => 1,
            'prize_awarded' => $prize,
            'submitted_by_staff_id' => $this->staff->id,
        ]);
    }

    public function test_finalizing_recomputes_locks_and_pays_the_top_ranks(): void
    {
        $period = $this->makePeriod('daily');
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->giveResult($first, 1, '100.00');
        $this->giveResult($second, 1, '50.00');

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);

        $period->refresh();
        $this->assertSame('finalized', $period->status);
        $this->assertNotNull($period->finalized_at);

        // config/minimax.php: daily rank 1 => 50.00, rank 2 => 30.00
        $this->assertSame('50.00', $first->fresh()->wallet->eligible_winnings_balance);
        $this->assertSame('30.00', $second->fresh()->wallet->eligible_winnings_balance);
        $this->assertDatabaseHas('leaderboard_reward_payouts', ['user_id' => $first->id, 'rank' => 1, 'amount' => '50.00']);
        $this->assertDatabaseHas('leaderboard_reward_payouts', ['user_id' => $second->id, 'rank' => 2, 'amount' => '30.00']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leaderboard.finalized']);
    }

    public function test_finalizing_twice_never_double_pays(): void
    {
        $period = $this->makePeriod('daily');
        $user = User::factory()->create();
        $this->giveResult($user, 1, '100.00');

        $action = app(FinalizeLeaderboardPeriodAction::class);
        $action->execute($period);
        $action->execute($period->fresh());

        $this->assertSame('50.00', $user->fresh()->wallet->eligible_winnings_balance);
        $this->assertSame(1, LeaderboardRewardPayout::where('user_id', $user->id)->count());
    }

    public function test_a_rank_with_no_configured_reward_is_paid_nothing(): void
    {
        $period = $this->makePeriod('daily');
        $user = User::factory()->create();
        $this->giveResult($user, 1, '100.00');

        // Four distinct users at ranks 1–4; only ranks 1–3 are configured.
        User::factory()->count(3)->create()->each(function (User $other, int $i) {
            $this->giveResult($other, 1, (string) (90 - $i * 10));
        });

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);

        $this->assertSame(3, LeaderboardRewardPayout::where('leaderboard_period_id', $period->id)->count());
    }

    public function test_finalized_entries_do_not_change_even_if_new_results_arrive_later(): void
    {
        $period = $this->makePeriod('daily');
        $user = User::factory()->create();
        $this->giveResult($user, 1, '100.00');

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);
        $entryCountAfterFinalize = $period->fresh()->entries()->count();

        // A late result for a brand-new player arrives after finalization.
        $this->giveResult(User::factory()->create(), 1, '999.00');
        app(RecomputeLeaderboardAction::class)->execute($period->fresh());

        $this->assertSame($entryCountAfterFinalize, $period->fresh()->entries()->count());
    }
}
