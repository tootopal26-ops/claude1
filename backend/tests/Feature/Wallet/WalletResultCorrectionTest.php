<?php

namespace Tests\Feature\Wallet;

use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletResultCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_positive_delta_credits_the_winnings_balance(): void
    {
        $user = User::factory()->create();

        app(WalletService::class)->adjustWinningsBalance($user, '60.00', 'result_correction', 'tournament.prize:1:1');

        $this->assertSame('60.00', $user->fresh()->wallet->eligible_winnings_balance);
    }

    public function test_a_later_increase_only_credits_the_difference(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        $wallet->adjustWinningsBalance($user, '60.00', 'result_correction', 'tournament.prize:1:1');
        $wallet->adjustWinningsBalance($user, '20.00', 'result_correction', 'tournament.prize:1:2'); // 80 - 60 = 20

        $this->assertSame('80.00', $user->fresh()->wallet->eligible_winnings_balance);
    }

    public function test_a_later_decrease_debits_the_difference(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        $wallet->adjustWinningsBalance($user, '60.00', 'result_correction', 'tournament.prize:1:1');
        $wallet->adjustWinningsBalance($user, '-10.00', 'result_correction', 'tournament.prize:1:2'); // 50 - 60 = -10

        $this->assertSame('50.00', $user->fresh()->wallet->eligible_winnings_balance);
    }

    public function test_a_decrease_beyond_the_available_winnings_is_rejected_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);
        $wallet->adjustWinningsBalance($user, '10.00', 'result_correction', 'tournament.prize:1:1');

        $this->expectException(InsufficientWalletBalanceException::class);

        try {
            $wallet->adjustWinningsBalance($user, '-50.00', 'result_correction', 'tournament.prize:1:2');
        } finally {
            $this->assertSame('10.00', $user->fresh()->wallet->eligible_winnings_balance);
        }
    }

    public function test_a_zero_delta_is_a_no_op_and_returns_null(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);
        $wallet->adjustWinningsBalance($user, '60.00', 'result_correction', 'tournament.prize:1:1');

        $result = $wallet->adjustWinningsBalance($user, '0.00', 'result_correction', 'tournament.prize:1:2');

        $this->assertNull($result);
        $this->assertSame('60.00', $user->fresh()->wallet->eligible_winnings_balance);
    }

    public function test_calling_with_the_same_idempotency_key_twice_applies_it_once(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        $wallet->adjustWinningsBalance($user, '60.00', 'result_correction', 'tournament.prize:1:1');
        $wallet->adjustWinningsBalance($user, '60.00', 'result_correction', 'tournament.prize:1:1');

        $this->assertSame('60.00', $user->fresh()->wallet->eligible_winnings_balance);
    }
}
