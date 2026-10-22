<?php

namespace Tests\Feature\Hardening;

use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\WithdrawalService;
use App\Models\RiskAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskAlertTriggersTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_failed_login_raises_a_risk_alert_exactly_at_the_threshold(): void
    {
        $user = User::factory()->create(['mobile' => '9123450000']);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['mobile' => '9123450000', 'password' => 'wrong'])->assertStatus(422);
            $this->assertDatabaseMissing('risk_alerts', ['type' => 'repeated_failed_login']);
        }

        // The 5th failure crosses the threshold.
        $this->postJson('/api/v1/auth/login', ['mobile' => '9123450000', 'password' => 'wrong'])->assertStatus(422);

        $this->assertDatabaseHas('risk_alerts', [
            'type' => 'repeated_failed_login',
            'risk_level' => 'high',
            'target_user_id' => $user->id,
        ]);
        $this->assertSame(1, RiskAlert::where('type', 'repeated_failed_login')->count());

        // A 6th failure past the threshold must not raise a second alert.
        // (The 'login' RateLimiter — a separate, unrelated 5/minute throttle
        // — may itself respond 429 here rather than letting the attempt
        // reach the controller; either way no second alert should appear.)
        $this->postJson('/api/v1/auth/login', ['mobile' => '9123450000', 'password' => 'wrong']);
        $this->assertSame(1, RiskAlert::where('type', 'repeated_failed_login')->count());
    }

    public function test_an_unknown_mobile_still_accumulates_failures_without_a_target_user(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['mobile' => '9999999999', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->assertDatabaseHas('risk_alerts', ['type' => 'repeated_failed_login', 'target_user_id' => null]);
    }

    public function test_a_successful_login_does_not_count_toward_the_failure_threshold(): void
    {
        $user = User::factory()->create(['mobile' => '9123450001', 'password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['mobile' => '9123450001', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['mobile' => '9123450001', 'password' => 'correct-password'])->assertOk();

        $this->assertDatabaseHas('login_attempts', ['mobile' => '9123450001', 'successful' => true]);
        $this->assertDatabaseMissing('risk_alerts', ['type' => 'repeated_failed_login']);
    }

    public function test_concurrent_wallet_op_threshold_raises_a_risk_alert_for_rapid_activity(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        for ($i = 1; $i <= 4; $i++) {
            $wallet->creditDeposit($user, '1.00', "rapid:{$i}");
            $this->assertDatabaseMissing('risk_alerts', ['type' => 'concurrent_wallet_op']);
        }

        // The 5th entry within the window crosses the threshold.
        $wallet->creditDeposit($user, '1.00', 'rapid:5');

        $this->assertDatabaseHas('risk_alerts', [
            'type' => 'concurrent_wallet_op',
            'risk_level' => 'medium',
            'target_user_id' => $user->id,
        ]);
        $this->assertSame(1, RiskAlert::where('type', 'concurrent_wallet_op')->count());
    }

    public function test_fewer_entries_than_the_threshold_does_not_raise_an_alert(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        $wallet->creditDeposit($user, '1.00', 'slow:1');
        $wallet->creditDeposit($user, '1.00', 'slow:2');

        $this->assertDatabaseMissing('risk_alerts', ['type' => 'concurrent_wallet_op']);
    }

    public function test_suspicious_withdrawal_pattern_raises_a_risk_alert_at_the_threshold(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->creditWinnings($user, '1000.00', 'seed:'.$user->id);
        $withdrawals = app(WithdrawalService::class);

        $withdrawals->request($user, '10.00', 'a@upi');
        $this->assertDatabaseMissing('risk_alerts', ['type' => 'suspicious_withdrawal_pattern']);

        $withdrawals->request($user, '10.00', 'b@upi');
        $this->assertDatabaseMissing('risk_alerts', ['type' => 'suspicious_withdrawal_pattern']);

        // The 3rd request within the window crosses the threshold.
        $withdrawals->request($user, '10.00', 'c@upi');

        $this->assertDatabaseHas('risk_alerts', [
            'type' => 'suspicious_withdrawal_pattern',
            'risk_level' => 'medium',
            'target_user_id' => $user->id,
        ]);
        $this->assertSame(1, RiskAlert::where('type', 'suspicious_withdrawal_pattern')->count());
    }

    public function test_fewer_withdrawal_requests_than_the_threshold_does_not_raise_an_alert(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->creditWinnings($user, '1000.00', 'seed:'.$user->id);
        $withdrawals = app(WithdrawalService::class);

        $withdrawals->request($user, '10.00', 'a@upi');
        $withdrawals->request($user, '10.00', 'b@upi');

        $this->assertDatabaseMissing('risk_alerts', ['type' => 'suspicious_withdrawal_pattern']);
    }
}
