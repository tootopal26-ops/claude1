<?php

namespace Tests\Feature\Tournament;

use App\Domain\Tournament\ReleaseCredentialsAction;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentSchedulerCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_join_window_closes_an_upcoming_tournament_past_its_deadline(): void
    {
        $tournament = Tournament::factory()->create([
            'status' => 'upcoming',
            'scheduled_start_at' => now()->subMinutes(5),
        ]);

        $this->artisan('tournaments:close-joining-window')->assertSuccessful();

        $this->assertSame('ongoing', $tournament->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tournament.join_window_closed']);
    }

    public function test_the_join_window_leaves_a_tournament_still_within_its_window_untouched(): void
    {
        $tournament = Tournament::factory()->create([
            'status' => 'upcoming',
            'scheduled_start_at' => now()->addMinutes(10),
        ]);

        $this->artisan('tournaments:close-joining-window')->assertSuccessful();

        $this->assertSame('upcoming', $tournament->fresh()->status);
    }

    public function test_the_join_window_command_is_idempotent_on_rerun(): void
    {
        $tournament = Tournament::factory()->create([
            'status' => 'upcoming',
            'scheduled_start_at' => now()->subMinutes(5),
        ]);

        $this->artisan('tournaments:close-joining-window')->assertSuccessful();
        $this->artisan('tournaments:close-joining-window')->assertSuccessful();

        $this->assertSame('ongoing', $tournament->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'tournament.join_window_closed')
            ->where('subject_id', $tournament->id)->count());
    }

    public function test_an_underfilled_paid_tournament_is_auto_cancelled_and_refunded(): void
    {
        $tournament = Tournament::factory()->paid(50)->create([
            'status' => 'ongoing',
            'min_players' => 2,
            'scheduled_start_at' => now()->subMinutes(10),
        ]);

        $user = User::factory()->create();
        app(WalletService::class)->creditDeposit($user, '100.00', 'seed:'.$user->id);
        $entry = TournamentEntry::factory()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'entry_fee_paid' => 50,
        ]);
        app(WalletService::class)->debitForEntryFee($user, '50.00', "tournament.entry_fee:{$entry->id}", $entry);

        $this->artisan('tournaments:auto-cancel-underfilled')->assertSuccessful();

        $tournament->refresh();
        $this->assertSame('cancelled', $tournament->status);
        $this->assertSame('min_players_not_met', $tournament->cancellation_reason);
        $this->assertSame('refunded', $entry->fresh()->status);
        $this->assertSame('100.00', $user->fresh()->wallet->deposit_balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tournament.auto_cancelled']);
    }

    public function test_a_free_entry_in_an_auto_cancelled_tournament_is_marked_cancelled_not_refunded(): void
    {
        $tournament = Tournament::factory()->create([
            'status' => 'ongoing',
            'min_players' => 2,
            'scheduled_start_at' => now()->subMinutes(10),
        ]);
        $entry = TournamentEntry::factory()->create([
            'tournament_id' => $tournament->id,
            'entry_fee_paid' => 0,
        ]);

        $this->artisan('tournaments:auto-cancel-underfilled')->assertSuccessful();

        $this->assertSame('cancelled', $entry->fresh()->status);
    }

    public function test_a_tournament_that_met_its_minimum_is_not_cancelled(): void
    {
        $tournament = Tournament::factory()->create([
            'status' => 'ongoing',
            'min_players' => 1,
            'scheduled_start_at' => now()->subMinutes(10),
        ]);
        TournamentEntry::factory()->create(['tournament_id' => $tournament->id]);

        $this->artisan('tournaments:auto-cancel-underfilled')->assertSuccessful();

        $this->assertSame('ongoing', $tournament->fresh()->status);
    }

    public function test_a_tournament_with_released_credentials_is_never_auto_cancelled_even_if_underfilled(): void
    {
        $tournament = Tournament::factory()->create([
            'status' => 'upcoming',
            'min_players' => 5,
            'scheduled_start_at' => now()->subMinutes(10),
        ]);
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        app(ReleaseCredentialsAction::class)->execute($tournament, 'ROOM', 'PASS', $staff);

        $this->artisan('tournaments:auto-cancel-underfilled')->assertSuccessful();

        $this->assertSame('ongoing', $tournament->fresh()->status);
    }

    public function test_the_auto_cancel_command_is_idempotent_and_never_double_refunds(): void
    {
        $tournament = Tournament::factory()->paid(50)->create([
            'status' => 'ongoing',
            'min_players' => 2,
            'scheduled_start_at' => now()->subMinutes(10),
        ]);

        $user = User::factory()->create();
        app(WalletService::class)->creditDeposit($user, '100.00', 'seed:'.$user->id);
        $entry = TournamentEntry::factory()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'entry_fee_paid' => 50,
        ]);
        app(WalletService::class)->debitForEntryFee($user, '50.00', "tournament.entry_fee:{$entry->id}", $entry);

        $this->artisan('tournaments:auto-cancel-underfilled')->assertSuccessful();
        $this->artisan('tournaments:auto-cancel-underfilled')->assertSuccessful();

        $this->assertSame('100.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_a_tournament_without_a_configured_minimum_is_never_auto_cancelled(): void
    {
        $tournament = Tournament::factory()->create([
            'status' => 'ongoing',
            'min_players' => null,
            'scheduled_start_at' => now()->subMinutes(10),
        ]);

        $this->artisan('tournaments:auto-cancel-underfilled')->assertSuccessful();

        $this->assertSame('ongoing', $tournament->fresh()->status);
    }
}
