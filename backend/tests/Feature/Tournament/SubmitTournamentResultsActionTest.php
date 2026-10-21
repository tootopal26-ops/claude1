<?php

namespace Tests\Feature\Tournament;

use App\Domain\Tournament\SubmitTournamentResultsAction;
use App\Domain\Wallet\WalletService;
use App\Models\Notification;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubmitTournamentResultsActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTournament(array $overrides = []): Tournament
    {
        return Tournament::factory()->create(array_merge([
            'status' => 'ongoing',
            'prize_pool' => 100,
        ], $overrides));
    }

    private function makeEntry(Tournament $tournament, ?User $user = null): TournamentEntry
    {
        return TournamentEntry::factory()->create([
            'tournament_id' => $tournament->id,
            'user_id' => ($user ?? User::factory()->create())->id,
        ]);
    }

    public function test_publishing_results_credits_each_entrys_prize_and_marks_the_tournament_completed(): void
    {
        $tournament = $this->makeTournament();
        $entryA = $this->makeEntry($tournament);
        $entryB = $this->makeEntry($tournament);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $revision = app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entryA->id, 'rank' => 1, 'kills' => 10, 'prize_awarded' => '60.00'],
            ['tournament_entry_id' => $entryB->id, 'rank' => 2, 'kills' => 5, 'prize_awarded' => '40.00'],
        ], $staff);

        $this->assertSame(1, $revision);
        $this->assertSame('completed', $tournament->fresh()->status);
        $this->assertSame('60.00', $entryA->user->fresh()->wallet->eligible_winnings_balance);
        $this->assertSame('40.00', $entryB->user->fresh()->wallet->eligible_winnings_balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tournament.results_published']);
        $this->assertSame(1, Notification::where('user_id', $entryA->user_id)->count());
    }

    public function test_total_prize_exceeding_the_prize_pool_is_rejected(): void
    {
        $tournament = $this->makeTournament(['prize_pool' => 50]);
        $entry = $this->makeEntry($tournament);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->expectException(ValidationException::class);

        try {
            app(SubmitTournamentResultsAction::class)->execute($tournament, [
                ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 0, 'prize_awarded' => '60.00'],
            ], $staff);
        } finally {
            $this->assertSame('0.00', $entry->user->fresh()->wallet->eligible_winnings_balance);
            $this->assertDatabaseCount('tournament_results', 0);
        }
    }

    public function test_omitting_a_confirmed_entry_is_rejected(): void
    {
        $tournament = $this->makeTournament();
        $entryA = $this->makeEntry($tournament);
        $this->makeEntry($tournament); // entryB, left out of the submission
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->expectException(ValidationException::class);
        app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entryA->id, 'rank' => 1, 'kills' => 0, 'prize_awarded' => '0.00'],
        ], $staff);
    }

    public function test_an_unknown_entry_id_is_rejected(): void
    {
        $tournament = $this->makeTournament();
        $entry = $this->makeEntry($tournament);
        $otherTournament = $this->makeTournament();
        $foreignEntry = $this->makeEntry($otherTournament);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->expectException(ValidationException::class);
        app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 0, 'prize_awarded' => '0.00'],
            ['tournament_entry_id' => $foreignEntry->id, 'rank' => 2, 'kills' => 0, 'prize_awarded' => '0.00'],
        ], $staff);
    }

    public function test_a_duplicate_entry_in_the_same_submission_is_rejected(): void
    {
        $tournament = $this->makeTournament();
        $entry = $this->makeEntry($tournament);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->expectException(ValidationException::class);
        app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 0, 'prize_awarded' => '0.00'],
            ['tournament_entry_id' => $entry->id, 'rank' => 2, 'kills' => 0, 'prize_awarded' => '0.00'],
        ], $staff);
    }

    public function test_results_cannot_be_submitted_for_an_upcoming_tournament(): void
    {
        $tournament = $this->makeTournament(['status' => 'upcoming']);
        $entry = $this->makeEntry($tournament);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->expectException(ValidationException::class);
        app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 0, 'prize_awarded' => '0.00'],
        ], $staff);
    }

    public function test_a_revision_increasing_a_prize_credits_only_the_difference(): void
    {
        $tournament = $this->makeTournament();
        $entry = $this->makeEntry($tournament);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $action = app(SubmitTournamentResultsAction::class);

        $action->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 2, 'kills' => 3, 'prize_awarded' => '20.00'],
        ], $staff);

        $revision = $action->execute($tournament->fresh(), [
            ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 3, 'prize_awarded' => '60.00'],
        ], $staff);

        $this->assertSame(2, $revision);
        $this->assertSame('60.00', $entry->user->fresh()->wallet->eligible_winnings_balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tournament.results_revised']);

        $current = $entry->fresh()->currentResult();
        $this->assertSame(1, $current->rank);
        $this->assertSame(2, TournamentResult::count());
    }

    public function test_a_revision_decreasing_a_prize_debits_only_the_difference(): void
    {
        $tournament = $this->makeTournament();
        $entry = $this->makeEntry($tournament);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $action = app(SubmitTournamentResultsAction::class);

        $action->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 3, 'prize_awarded' => '60.00'],
        ], $staff);

        $action->execute($tournament->fresh(), [
            ['tournament_entry_id' => $entry->id, 'rank' => 2, 'kills' => 3, 'prize_awarded' => '20.00'],
        ], $staff);

        $this->assertSame('20.00', $entry->user->fresh()->wallet->eligible_winnings_balance);
    }

    public function test_a_revision_that_cannot_be_applied_because_winnings_were_already_spent_is_rejected_and_rolled_back(): void
    {
        $tournament = $this->makeTournament();
        $user = User::factory()->create();
        $entry = $this->makeEntry($tournament, $user);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $action = app(SubmitTournamentResultsAction::class);

        $action->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 3, 'prize_awarded' => '60.00'],
        ], $staff);

        // Player withdraws/spends their winnings elsewhere before the correction.
        app(WalletService::class)->manualAdjust($user, '-60.00', 'winnings', 'test spend', $staff, 'spend:1');

        $this->expectException(ValidationException::class);

        try {
            $action->execute($tournament->fresh(), [
                ['tournament_entry_id' => $entry->id, 'rank' => 5, 'kills' => 3, 'prize_awarded' => '10.00'],
            ], $staff);
        } finally {
            $this->assertSame(1, $entry->fresh()->currentResult()->revision_number);
            $this->assertSame('60.00', $entry->fresh()->currentResult()->prize_awarded);
        }
    }

    public function test_a_free_tournament_with_zero_prizes_records_rank_and_kills_without_touching_wallets(): void
    {
        $tournament = $this->makeTournament(['prize_pool' => 0, 'entry_fee' => 0]);
        $entry = $this->makeEntry($tournament);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        app(SubmitTournamentResultsAction::class)->execute($tournament, [
            ['tournament_entry_id' => $entry->id, 'rank' => 1, 'kills' => 12, 'prize_awarded' => '0.00'],
        ], $staff);

        $this->assertSame('0.00', $entry->user->fresh()->wallet->eligible_winnings_balance);
        $this->assertSame(12, $entry->fresh()->currentResult()->kills);
    }
}
