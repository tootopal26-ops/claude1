<?php

namespace Tests\Feature;

use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clean_wallet_reports_no_drift(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->creditDeposit($user, '50.00', 'deposit:1');

        $this->artisan('wallet:reconcile')
            ->expectsOutput('All wallets reconcile cleanly against their ledgers.')
            ->assertExitCode(0);
    }

    public function test_drift_is_detected_and_corrected(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->creditDeposit($user, '100.00', 'deposit:1');
        $user->wallet->forceFill(['deposit_balance' => '999.00'])->save();

        $this->artisan('wallet:reconcile')->assertExitCode(0);

        $this->assertSame('100.00', (string) $user->wallet->fresh()->deposit_balance);
    }

    public function test_dry_run_reports_drift_without_correcting_it(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->creditDeposit($user, '100.00', 'deposit:1');
        $user->wallet->forceFill(['deposit_balance' => '999.00'])->save();

        $this->artisan('wallet:reconcile --dry-run')->assertExitCode(0);

        $this->assertSame('999.00', (string) $user->wallet->fresh()->deposit_balance);
    }
}
