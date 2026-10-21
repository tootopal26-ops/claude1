<?php

namespace Tests\Feature\Wallet;

use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\RiskAlert;
use App\Models\StaffUser;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletService::class);
    }

    public function test_every_new_user_gets_a_wallet_automatically(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->wallet);
        $this->assertSame('0.00', (string) $user->wallet->deposit_balance);
    }

    public function test_crediting_a_deposit_increases_the_deposit_balance(): void
    {
        $user = User::factory()->create();

        $this->wallet->creditDeposit($user, '100.00', 'deposit:order-1');

        $this->assertSame('100.00', (string) $user->wallet->fresh()->deposit_balance);
    }

    public function test_crediting_a_deposit_twice_with_the_same_key_is_idempotent(): void
    {
        $user = User::factory()->create();

        $this->wallet->creditDeposit($user, '100.00', 'deposit:order-1');
        $this->wallet->creditDeposit($user, '100.00', 'deposit:order-1');

        $this->assertSame('100.00', (string) $user->wallet->fresh()->deposit_balance);
        $this->assertSame(1, WalletLedgerEntry::where('idempotency_key', 'deposit:order-1')->count());
    }

    public function test_crediting_winnings_increases_the_eligible_winnings_balance(): void
    {
        $user = User::factory()->create();

        $this->wallet->creditWinnings($user, '50.00', 'winning:result-1');

        $this->assertSame('50.00', (string) $user->wallet->fresh()->eligible_winnings_balance);
    }

    public function test_entry_fee_is_deducted_from_deposit_first_then_winnings_exactly_as_the_spec_example(): void
    {
        // The project spec's own worked example: fee 100, deposit 70,
        // winnings 50 → 70 from deposit + 30 from winnings.
        $user = User::factory()->create();
        $this->wallet->creditDeposit($user, '70.00', 'deposit:1');
        $this->wallet->creditWinnings($user, '50.00', 'winning:1');

        $result = $this->wallet->debitForEntryFee($user, '100.00', 'entry_fee:1');

        $this->assertSame('70.00', $result['deposit_used']);
        $this->assertSame('30.00', $result['winnings_used']);
        $this->assertCount(2, $result['entries']);

        $fresh = $user->wallet->fresh();
        $this->assertSame('0.00', (string) $fresh->deposit_balance);
        $this->assertSame('20.00', (string) $fresh->eligible_winnings_balance);
    }

    public function test_entry_fee_is_covered_entirely_by_deposit_when_sufficient(): void
    {
        $user = User::factory()->create();
        $this->wallet->creditDeposit($user, '200.00', 'deposit:1');
        $this->wallet->creditWinnings($user, '50.00', 'winning:1');

        $result = $this->wallet->debitForEntryFee($user, '100.00', 'entry_fee:1');

        $this->assertSame('100.00', $result['deposit_used']);
        $this->assertSame('0.00', $result['winnings_used']);
        $this->assertSame('50.00', (string) $user->wallet->fresh()->eligible_winnings_balance);
    }

    public function test_entry_fee_is_rejected_when_combined_balance_is_insufficient(): void
    {
        $user = User::factory()->create();
        $this->wallet->creditDeposit($user, '30.00', 'deposit:1');
        $this->wallet->creditWinnings($user, '20.00', 'winning:1');

        $this->expectException(InsufficientWalletBalanceException::class);
        $this->wallet->debitForEntryFee($user, '100.00', 'entry_fee:1');
    }

    public function test_a_rejected_entry_fee_debit_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->wallet->creditDeposit($user, '30.00', 'deposit:1');

        try {
            $this->wallet->debitForEntryFee($user, '100.00', 'entry_fee:1');
        } catch (InsufficientWalletBalanceException) {
            // expected
        }

        $this->assertSame('30.00', (string) $user->wallet->fresh()->deposit_balance);
        $this->assertSame(0, WalletLedgerEntry::where('idempotency_key', 'entry_fee:1')->count());
    }

    public function test_debiting_an_entry_fee_twice_with_the_same_key_does_not_double_charge(): void
    {
        $user = User::factory()->create();
        $this->wallet->creditDeposit($user, '100.00', 'deposit:1');

        $first = $this->wallet->debitForEntryFee($user, '40.00', 'entry_fee:1');
        $second = $this->wallet->debitForEntryFee($user, '40.00', 'entry_fee:1');

        $this->assertSame($first['deposit_used'], $second['deposit_used']);
        $this->assertSame('60.00', (string) $user->wallet->fresh()->deposit_balance);
    }

    public function test_a_refund_restores_the_exact_original_split(): void
    {
        $user = User::factory()->create();
        $this->wallet->creditDeposit($user, '70.00', 'deposit:1');
        $this->wallet->creditWinnings($user, '50.00', 'winning:1');
        $this->wallet->debitForEntryFee($user, '100.00', 'entry_fee:1');

        $this->wallet->refundExact($user, 'entry_fee:1', 'refund:1');

        $fresh = $user->wallet->fresh();
        $this->assertSame('70.00', (string) $fresh->deposit_balance);
        $this->assertSame('50.00', (string) $fresh->eligible_winnings_balance);
    }

    public function test_refunding_twice_with_the_same_key_never_double_refunds(): void
    {
        $user = User::factory()->create();
        $this->wallet->creditDeposit($user, '100.00', 'deposit:1');
        $this->wallet->debitForEntryFee($user, '100.00', 'entry_fee:1');

        $this->wallet->refundExact($user, 'entry_fee:1', 'refund:1');
        $this->wallet->refundExact($user, 'entry_fee:1', 'refund:1');

        $this->assertSame('100.00', (string) $user->wallet->fresh()->deposit_balance);
    }

    public function test_refunding_a_key_with_no_debit_throws(): void
    {
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->wallet->refundExact($user, 'nonexistent-key', 'refund:1');
    }

    public function test_a_positive_manual_adjustment_increases_the_balance_and_is_audited(): void
    {
        $user = User::factory()->create();
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->wallet->manualAdjust($user, '50.00', 'deposit', 'Goodwill credit', $staff, 'adj:1');

        $this->assertSame('50.00', (string) $user->wallet->fresh()->deposit_balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet.manual_adjustment']);
    }

    public function test_a_negative_manual_adjustment_beyond_the_balance_is_rejected(): void
    {
        $user = User::factory()->create();
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $this->wallet->manualAdjust($user, '20.00', 'deposit', 'Initial', $staff, 'adj:1');

        $this->expectException(InsufficientWalletBalanceException::class);
        $this->wallet->manualAdjust($user, '-50.00', 'deposit', 'Over-correction', $staff, 'adj:2');
    }

    public function test_a_manual_adjustment_at_or_above_the_threshold_raises_a_risk_alert(): void
    {
        $user = User::factory()->create();
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->wallet->manualAdjust($user, '200.00', 'deposit', 'Large credit', $staff, 'adj:1');

        $alert = RiskAlert::sole();
        $this->assertSame('manual_wallet_adjustment_over_threshold', $alert->type);
        $this->assertSame('high', $alert->risk_level);
        $this->assertSame($staff->id, $alert->actor_staff_id);
        $this->assertSame($user->id, $alert->target_user_id);
    }

    public function test_a_manual_adjustment_below_the_threshold_does_not_raise_a_risk_alert(): void
    {
        $user = User::factory()->create();
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->wallet->manualAdjust($user, '50.00', 'deposit', 'Small credit', $staff, 'adj:1');

        $this->assertSame(0, RiskAlert::count());
    }

    public function test_a_negative_manual_adjustment_at_the_threshold_also_raises_a_risk_alert(): void
    {
        $user = User::factory()->create();
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        // Funded via creditDeposit (not manualAdjust) so the only risk
        // alert this test should see is from the targeted -200 adjustment.
        $this->wallet->creditDeposit($user, '300.00', 'deposit:1');

        $this->wallet->manualAdjust($user, '-200.00', 'deposit', 'Large correction', $staff, 'adj:2');

        $this->assertSame(1, RiskAlert::count());
    }

    /**
     * Same rationale as TournamentJoinTest's concurrency test: a single
     * PHPUnit process only ever holds one DB connection, so it can't
     * actually exercise two transactions racing for the same wallet row
     * lock. This spawns several independent PHP processes against the
     * real `minimax_testing` MySQL database and proves lockForUpdate()
     * lets a 100-balance wallet be debited exactly once by 100, never
     * oversold across concurrent attempts.
     */
    public function test_concurrent_entry_fee_debits_never_oversell_a_wallet(): void
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to prepare the concurrency fixture.');
        }

        $env = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'minimax_testing'];

        $migrate = Process::env($env)->path(base_path())->run('php artisan migrate:fresh --no-interaction');
        $this->assertTrue($migrate->successful(), $migrate->errorOutput());

        $seed = Process::env($env)->path(base_path())->run('php artisan app:seed-wallet-concurrency-fixture 100.00');
        $this->assertTrue($seed->successful(), $seed->errorOutput());
        $fixture = json_decode(trim($seed->output()), true, flags: JSON_THROW_ON_ERROR);
        $userId = $fixture['user_id'];

        $attempts = 8;
        $results = Process::pool(function ($pool) use ($env, $userId, $attempts) {
            for ($i = 0; $i < $attempts; $i++) {
                $pool->as((string) $i)
                    ->env($env)
                    ->path(base_path())
                    ->command("php artisan app:attempt-wallet-debit {$userId} 100.00 entry_fee:concurrent:{$i}");
            }
        })->start()->wait();

        $debited = collect($results)->filter(fn ($result) => str_contains($result->output(), 'DEBITED'));
        $rejected = collect($results)->filter(fn ($result) => str_contains($result->output(), 'REJECTED'));

        $this->assertCount(1, $debited, 'Exactly one of the concurrent 100-coin debits should succeed against a 100-coin balance.');
        $this->assertCount($attempts - 1, $rejected);

        config(['database.connections.concurrency_check' => array_merge(
            config('database.connections.mysql'),
            ['database' => 'minimax_testing'],
        )]);
        DB::purge('concurrency_check');

        $finalBalance = DB::connection('concurrency_check')
            ->table('wallets')->where('user_id', $userId)->value('deposit_balance');

        $this->assertSame('0.00', $finalBalance, 'The wallet must never go negative or be debited more than once.');
    }
}
