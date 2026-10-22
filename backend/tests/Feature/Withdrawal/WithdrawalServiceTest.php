<?php

namespace Tests\Feature\Withdrawal;

use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\WithdrawalService;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WithdrawalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fundWinnings(User $user, string $amount): void
    {
        app(WalletService::class)->creditWinnings($user, $amount, 'seed:'.$user->id.':'.$amount);
    }

    public function test_requesting_a_withdrawal_atomically_reserves_the_amount(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');

        $withdrawal = app(WithdrawalService::class)->request($user, '40.00', 'player@upi');

        $this->assertSame('pending', $withdrawal->status);
        $this->assertSame('40.00', $withdrawal->amount);
        $this->assertSame('60.00', $user->fresh()->wallet->eligible_winnings_balance);
    }

    public function test_requesting_more_than_the_available_winnings_is_rejected_and_creates_no_request(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '20.00');

        $this->expectException(ValidationException::class);

        try {
            app(WithdrawalService::class)->request($user, '50.00', 'player@upi');
        } finally {
            $this->assertSame('20.00', $user->fresh()->wallet->eligible_winnings_balance);
            $this->assertDatabaseCount('withdrawal_requests', 0);
        }
    }

    public function test_requesting_a_non_positive_amount_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '20.00');

        $this->expectException(ValidationException::class);
        app(WithdrawalService::class)->request($user, '0.00', 'player@upi');
    }

    public function test_the_full_happy_path_pending_to_paid(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($user, '40.00', 'player@upi');
        $service->approve($withdrawal, $staff);
        $this->assertSame('approved', $withdrawal->fresh()->status);

        $service->markProcessing($withdrawal->fresh(), $staff);
        $this->assertSame('processing', $withdrawal->fresh()->status);

        $service->markPaid($withdrawal->fresh(), 'UTR123456', $staff);
        $withdrawal->refresh();

        $this->assertSame('paid', $withdrawal->status);
        $this->assertSame('UTR123456', $withdrawal->settlement_reference);
        $this->assertNotNull($withdrawal->settled_at);
        // Paid doesn't touch the wallet again — it was already reserved at request time.
        $this->assertSame('60.00', $user->fresh()->wallet->eligible_winnings_balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.paid']);
    }

    public function test_marking_paid_without_a_settlement_reference_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($user, '40.00', 'player@upi');
        $service->approve($withdrawal->fresh(), $staff);
        $service->markProcessing($withdrawal->fresh(), $staff);

        $this->expectException(ValidationException::class);
        $service->markPaid($withdrawal->fresh(), '   ', $staff);
    }

    public function test_marking_paid_is_only_reachable_from_processing(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($user, '40.00', 'player@upi');

        $this->expectException(ValidationException::class);
        $service->markPaid($withdrawal, 'UTR123456', $staff);
    }

    public function test_rejecting_a_pending_withdrawal_releases_the_reservation(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($user, '40.00', 'player@upi');
        $this->assertSame('60.00', $user->fresh()->wallet->eligible_winnings_balance);

        $service->reject($withdrawal, 'Suspicious activity', $staff);

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertSame('Suspicious activity', $withdrawal->fresh()->rejection_reason);
        $this->assertSame('100.00', $user->fresh()->wallet->eligible_winnings_balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.rejected']);
    }

    public function test_rejecting_an_approved_withdrawal_also_releases_the_reservation(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($user, '40.00', 'player@upi');
        $service->approve($withdrawal->fresh(), $staff);
        $service->reject($withdrawal->fresh(), 'Changed mind', $staff);

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertSame('100.00', $user->fresh()->wallet->eligible_winnings_balance);
    }

    public function test_a_processing_withdrawal_cannot_be_rejected(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($user, '40.00', 'player@upi');
        $service->approve($withdrawal->fresh(), $staff);
        $service->markProcessing($withdrawal->fresh(), $staff);

        $this->expectException(ValidationException::class);
        $service->reject($withdrawal->fresh(), 'too late', $staff);
    }

    public function test_marking_a_processing_withdrawal_failed_releases_the_reservation(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($user, '40.00', 'player@upi');
        $service->approve($withdrawal->fresh(), $staff);
        $service->markProcessing($withdrawal->fresh(), $staff);
        $service->markFailed($withdrawal->fresh(), 'Bank rejected the transfer', $staff);

        $this->assertSame('failed', $withdrawal->fresh()->status);
        $this->assertSame('100.00', $user->fresh()->wallet->eligible_winnings_balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'withdrawal.failed']);
    }

    public function test_approving_an_already_approved_withdrawal_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);
        $service = app(WithdrawalService::class);

        $withdrawal = $service->request($user, '40.00', 'player@upi');
        $service->approve($withdrawal->fresh(), $staff);

        $this->expectException(ValidationException::class);
        $service->approve($withdrawal->fresh(), $staff);
    }

    /**
     * This is the one test in the suite that deliberately does NOT use
     * the sqlite in-memory connection PHPUnit otherwise runs under: an
     * in-process test can only ever hold a single DB connection, so it
     * can't actually exercise two different operations racing for the
     * same wallet row lock. Instead it spawns several real, independent
     * PHP processes against the shared `minimax_testing` MySQL database
     * (each gets its own connection) and proves WalletService's single
     * row lock correctly serializes a withdrawal request and an
     * entry-fee debit — two different callers, not the same operation
     * racing against itself like the existing wallet/tournament
     * concurrency tests — against the same wallet, with no lost update
     * to either balance column.
     */
    public function test_concurrent_withdrawal_requests_and_entry_fee_debits_never_corrupt_the_same_wallet(): void
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to prepare the concurrency fixture.');
        }

        $env = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'minimax_testing'];

        $migrate = Process::env($env)->path(base_path())->run('php artisan migrate:fresh --no-interaction');
        $this->assertTrue($migrate->successful(), $migrate->errorOutput());

        $seed = Process::env($env)->path(base_path())->run('php artisan app:seed-dual-balance-wallet-fixture 500.00 500.00');
        $this->assertTrue($seed->successful(), $seed->errorOutput());
        $fixture = json_decode(trim($seed->output()), true, flags: JSON_THROW_ON_ERROR);
        $userId = $fixture['user_id'];

        $perSide = 4;
        $results = Process::pool(function ($pool) use ($env, $userId, $perSide) {
            for ($i = 0; $i < $perSide; $i++) {
                $pool->as("debit-{$i}")
                    ->env($env)
                    ->path(base_path())
                    ->command("php artisan app:attempt-wallet-debit {$userId} 50.00 entry_fee:dual:{$i}");

                $pool->as("withdrawal-{$i}")
                    ->env($env)
                    ->path(base_path())
                    ->command("php artisan app:attempt-withdrawal-request {$userId} 50.00");
            }
        })->start()->wait();

        foreach ($results as $result) {
            $this->assertTrue($result->successful(), $result->errorOutput());
        }

        config(['database.connections.concurrency_check' => array_merge(
            config('database.connections.mysql'),
            ['database' => 'minimax_testing'],
        )]);
        DB::purge('concurrency_check');

        $wallet = DB::connection('concurrency_check')->table('wallets')->where('user_id', $userId)->first();
        $this->assertSame('300.00', $wallet->deposit_balance, 'All 4 entry-fee debits must have applied, and no more.');
        $this->assertSame('300.00', $wallet->eligible_winnings_balance, 'All 4 withdrawal reservations must have applied, and no more.');

        $withdrawalCount = DB::connection('concurrency_check')->table('withdrawal_requests')->where('user_id', $userId)->count();
        $this->assertSame(4, $withdrawalCount);
    }
}
