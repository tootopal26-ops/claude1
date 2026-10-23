<?php

namespace Tests\Feature\Leaderboard;

use App\Domain\Leaderboard\FinalizeLeaderboardPeriodAction;
use App\Domain\Leaderboard\LeaderboardPrizeService;
use App\Domain\Leaderboard\RecomputeLeaderboardAction;
use App\Domain\Wallet\WalletService;
use App\Livewire\Admin\LeaderboardPrizes;
use App\Livewire\Admin\LeaderboardSettlement;
use App\Models\LeaderboardPeriod;
use App\Models\LeaderboardPrizeRank;
use App\Models\LeaderboardPrizeRule;
use App\Models\LeaderboardRewardPayout;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentResult;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeaderboardPrizeRulesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    private function period(string $type = 'daily'): LeaderboardPeriod
    {
        return LeaderboardPeriod::create([
            'type' => $type,
            'period_start' => '2026-01-15 00:00:00',
            'period_end' => '2026-01-16 00:00:00',
            'status' => 'provisional',
        ]);
    }

    /**
     * Players ranked 1..$count by the prize they won (highest first).
     *
     * @return list<User>
     */
    private function rankedPlayers(int $count): array
    {
        $staff = StaffUser::factory()->create();
        $players = [];

        foreach (range(1, $count) as $position) {
            $user = User::factory()->create();
            $tournament = Tournament::factory()->create(['scheduled_start_at' => '2026-01-15 10:00:00']);
            $entry = TournamentEntry::factory()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id]);

            TournamentResult::create([
                'tournament_id' => $tournament->id,
                'tournament_entry_id' => $entry->id,
                'revision_number' => 1,
                'rank' => 1,
                'kills' => 1,
                'prize_awarded' => (string) (($count - $position + 1) * 100).'.00',
                'submitted_by_staff_id' => $staff->id,
            ]);

            $players[] = $user;
        }

        return $players;
    }

    private function setPrizes(string $type, int $first, int $second, int $third, int $rest, bool $enabled = true): void
    {
        app(LeaderboardPrizeService::class)->save($type, $enabled, $first, $second, $third, $rest, $this->admin());
    }

    private function winnings(User $user): string
    {
        return (string) $user->fresh()->wallet?->eligible_winnings_balance;
    }

    // --- Rules ---------------------------------------------------------------

    public function test_each_period_type_starts_with_the_prizes_the_platform_already_paid(): void
    {
        $this->assertSame([1 => 50, 2 => 30, 3 => 20], LeaderboardPrizeRule::where('period_type', 'daily')->first()->coinsByRank());
        $this->assertSame([1 => 300, 2 => 150, 3 => 100], LeaderboardPrizeRule::where('period_type', 'weekly')->first()->coinsByRank());
        $this->assertSame([1 => 1000, 2 => 500, 3 => 250], LeaderboardPrizeRule::where('period_type', 'monthly')->first()->coinsByRank());
        $this->assertSame(3, LeaderboardPrizeRule::count());
    }

    public function test_an_admin_saves_prizes_for_ranks_1_to_100_and_the_other_periods_are_untouched(): void
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardPrizes::class)
            ->set('type', 'weekly')
            ->set('first', '5000')
            ->set('second', '3000')
            ->set('third', '1500')
            ->set('bulkAmount', '100')
            ->call('applyBulk')
            ->assertSet('rest', '100')
            ->call('save')
            ->assertHasNoErrors();

        $weekly = LeaderboardPrizeRule::where('period_type', 'weekly')->first()->coinsByRank();

        $this->assertSame(5000, $weekly[1]);
        $this->assertSame(3000, $weekly[2]);
        $this->assertSame(1500, $weekly[3]);
        $this->assertCount(100, $weekly);
        $this->assertSame([100], array_values(array_unique(array_slice($weekly, 3, null, true))));
        $this->assertSame(100, $weekly[4]);
        $this->assertSame(100, $weekly[100]);

        // Daily and monthly keep their own, separate prizes.
        $this->assertSame([1 => 50, 2 => 30, 3 => 20], LeaderboardPrizeRule::where('period_type', 'daily')->first()->coinsByRank());
        $this->assertSame([1 => 1000, 2 => 500, 3 => 250], LeaderboardPrizeRule::where('period_type', 'monthly')->first()->coinsByRank());
        $this->assertDatabaseHas('audit_logs', ['action' => 'leaderboard.prizes_updated']);
    }

    public function test_prize_ranks_are_unique_per_period_in_the_database(): void
    {
        $rule = LeaderboardPrizeRule::where('period_type', 'daily')->first();

        $this->expectException(UniqueConstraintViolationException::class);

        LeaderboardPrizeRank::create(['leaderboard_prize_rule_id' => $rule->id, 'rank' => 1, 'coins' => 5]);
    }

    public function test_invalid_amounts_are_rejected_and_nothing_is_saved(): void
    {
        foreach (['-5', '12.5', 'abc', '1,000', '', '10000001', '1e3'] as $bad) {
            Livewire::actingAs($this->admin(), 'staff')
                ->test(LeaderboardPrizes::class)
                ->set('first', $bad)
                ->call('save')
                ->assertHasErrors('first');
        }

        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardPrizes::class)
            ->set('bulkAmount', '-1')
            ->call('applyBulk')
            ->assertHasErrors('bulkAmount');

        $this->assertSame([1 => 50, 2 => 30, 3 => 20], LeaderboardPrizeRule::where('period_type', 'daily')->first()->coinsByRank());
    }

    public function test_the_editor_tracks_unsaved_changes_and_cancel_discards_them(): void
    {
        $component = Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardPrizes::class)
            ->assertSet('hasChanges', false)
            ->set('first', '999')
            ->assertSet('hasChanges', true)
            ->call('cancel')
            ->assertSet('hasChanges', false)
            ->assertSet('first', '50');

        $component->set('bulkAmount', '7')->call('applyBulk')->assertSet('hasChanges', true)->assertSet('rest', '7');
        $this->assertSame([1 => 50, 2 => 30, 3 => 20], LeaderboardPrizeRule::where('period_type', 'daily')->first()->coinsByRank(), 'nothing is saved until Save');
    }

    public function test_saving_prizes_pays_nobody_and_credits_nothing(): void
    {
        $players = $this->rankedPlayers(3);

        $this->setPrizes('daily', 9999, 8888, 7777, 500);

        $this->assertSame(0, LeaderboardRewardPayout::count());
        $this->assertSame(0, WalletLedgerEntry::count());
        $this->assertSame('0.00', $this->winnings($players[0]));
    }

    public function test_the_prize_section_needs_leaderboards_manage_in_both_portals(): void
    {
        $preset = PermissionPreset::create(['name' => 'Viewer']);
        $preset->permissions()->attach(Permission::firstOrCreate(['key' => 'tournaments.view'], ['label' => 'x', 'group' => 'x']));
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id]);

        foreach (['prizes', 'settlement', 'banners.index', 'banners.create'] as $page) {
            $this->actingAs($staff, 'staff')->get(route('staff.leaderboards.'.$page))->assertForbidden();
            $this->actingAs($this->admin(), 'staff')->get(route('admin.leaderboards.'.$page))->assertOk();
        }

        Livewire::actingAs($staff, 'staff')->test(LeaderboardPrizes::class)->assertForbidden();
    }

    // --- Settlement ----------------------------------------------------------

    public function test_finalizing_pays_each_rank_from_the_saved_prizes_exactly_once(): void
    {
        $players = $this->rankedPlayers(6);
        $this->setPrizes('daily', 5000, 3000, 1500, 100);
        $period = $this->period();

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);
        app(FinalizeLeaderboardPeriodAction::class)->execute($period->fresh());

        $this->assertSame('5000.00', $this->winnings($players[0]));
        $this->assertSame('3000.00', $this->winnings($players[1]));
        $this->assertSame('1500.00', $this->winnings($players[2]));
        $this->assertSame('100.00', $this->winnings($players[3]));
        $this->assertSame('100.00', $this->winnings($players[5]));
        $this->assertSame(6, LeaderboardRewardPayout::count());
        $this->assertSame(6, WalletLedgerEntry::where('type', 'winning')->count(), 'one ledger credit per payout, even after a second run');
    }

    public function test_a_finalized_period_keeps_the_prizes_it_closed_with(): void
    {
        $players = $this->rankedPlayers(5);
        $this->setPrizes('daily', 500, 300, 200, 0);
        $period = $this->period();

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);
        $this->assertSame([1 => 500, 2 => 300, 3 => 200], $period->fresh()->prize_snapshot);

        // The admin now adds prizes for ranks 4-100 and raises rank 1, then someone re-runs the finalization.
        $this->setPrizes('daily', 9000, 300, 200, 250);
        app(FinalizeLeaderboardPeriodAction::class)->execute($period->fresh());

        $this->assertSame(3, LeaderboardRewardPayout::count(), 'ranks 4-5 must not be paid retroactively');
        $this->assertSame('500.00', $this->winnings($players[0]));
        $this->assertSame('0.00', $this->winnings($players[3]));
    }

    public function test_a_disabled_period_type_finalizes_but_pays_nothing(): void
    {
        $this->rankedPlayers(3);
        $this->setPrizes('daily', 500, 300, 200, 50, enabled: false);
        $period = $this->period();

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);

        $this->assertSame('finalized', $period->fresh()->status);
        $this->assertSame([], $period->fresh()->prize_snapshot);
        $this->assertSame(0, LeaderboardRewardPayout::count());
    }

    public function test_weekly_prizes_never_pay_a_daily_period(): void
    {
        $players = $this->rankedPlayers(2);
        $this->setPrizes('weekly', 90000, 80000, 70000, 60000);
        $period = $this->period('daily');

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);

        $this->assertSame('50.00', $this->winnings($players[0]), 'daily keeps the daily prize');
    }

    public function test_a_period_finalized_before_snapshots_existed_is_settled_from_the_original_config(): void
    {
        $players = $this->rankedPlayers(4);
        $period = $this->period();
        $period->update(['status' => 'finalized', 'finalized_at' => now()]);
        app(RecomputeLeaderboardAction::class)->execute($period->fresh()->forceFill(['status' => 'provisional']));
        $this->assertNull($period->fresh()->prize_snapshot);

        $this->setPrizes('daily', 7000, 7000, 7000, 7000);
        app(FinalizeLeaderboardPeriodAction::class)->payRewards($period->fresh());

        $this->assertSame(0, LeaderboardRewardPayout::where('rank', '>', 3)->count());
    }

    public function test_one_failing_payout_does_not_stop_the_others_and_stays_pending(): void
    {
        $players = $this->rankedPlayers(3);
        $period = $this->period();
        $real = app(WalletService::class);
        $broken = $players[1];

        $wallet = \Mockery::mock(WalletService::class);
        $wallet->shouldReceive('creditWinnings')->andReturnUsing(function (...$args) use ($real, $broken) {
            if ($args[0]->id === $broken->id) {
                throw new \RuntimeException('wallet unavailable');
            }

            return $real->creditWinnings(...$args);
        });
        $this->app->instance(WalletService::class, $wallet);

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);

        $this->assertSame(2, LeaderboardRewardPayout::count());
        $this->assertDatabaseMissing('leaderboard_reward_payouts', ['user_id' => $broken->id]);

        // The wallet recovers: a retry from the settlement page completes the missing payout, once.
        $this->app->instance(WalletService::class, $real);
        Livewire::actingAs($this->admin(), 'staff')->test(LeaderboardSettlement::class)->call('retry', $period->id);

        $this->assertSame(3, LeaderboardRewardPayout::count());
        $this->assertSame('30.00', $this->winnings($broken));

        Livewire::actingAs($this->admin(), 'staff')->test(LeaderboardSettlement::class)->call('retry', $period->id);
        $this->assertSame(3, LeaderboardRewardPayout::count());
        $this->assertSame(3, WalletLedgerEntry::where('type', 'winning')->count());
    }

    public function test_a_banned_player_is_withheld_listed_as_pending_and_paid_after_the_ban_is_lifted(): void
    {
        $players = $this->rankedPlayers(2);
        $players[0]->forceFill(['is_banned' => true])->save();
        $period = $this->period();

        app(FinalizeLeaderboardPeriodAction::class)->execute($period);

        $this->assertSame(1, LeaderboardRewardPayout::count());
        $this->assertSame('0.00', $this->winnings($players[0]));
        $this->assertSame('30.00', $this->winnings($players[1]), 'the prize is not passed down to the next rank');

        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardSettlement::class)
            ->assertSee('Withheld — account is banned')
            ->assertSee($players[0]->username);

        $players[0]->forceFill(['is_banned' => false])->save();
        Livewire::actingAs($this->admin(), 'staff')->test(LeaderboardSettlement::class)->call('retry', $period->id);

        $this->assertSame('50.00', $this->winnings($players[0]));
        $this->assertSame(2, LeaderboardRewardPayout::count());
    }

    public function test_the_settlement_page_lists_finalized_periods_with_paid_and_pending_counts(): void
    {
        $this->rankedPlayers(3);
        $period = $this->period();
        app(FinalizeLeaderboardPeriodAction::class)->execute($period);

        Livewire::actingAs($this->admin(), 'staff')
            ->test(LeaderboardSettlement::class)
            ->assertSee('Daily')
            ->assertSee('None');

        $this->assertDatabaseHas('leaderboard_periods', ['id' => $period->id, 'status' => 'finalized']);
    }
}
