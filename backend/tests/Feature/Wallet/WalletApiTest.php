<?php

namespace Tests\Feature\Wallet;

use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetching_the_wallet_requires_authentication(): void
    {
        $this->getJson('/api/v1/wallet')->assertUnauthorized();
    }

    public function test_a_player_sees_their_own_balances(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->creditDeposit($user, '70.00', 'deposit:1');
        app(WalletService::class)->creditWinnings($user, '50.00', 'winning:1');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/wallet');

        $response->assertOk()
            ->assertJsonPath('data.deposit_balance', '70.00')
            ->assertJsonPath('data.eligible_winnings_balance', '50.00')
            ->assertJsonPath('data.total_balance', '120.00')
            ->assertJsonPath('data.withdrawable_balance', '50.00');
    }

    public function test_a_player_sees_their_own_ledger_history_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        app(WalletService::class)->creditDeposit($user, '70.00', 'deposit:1');
        app(WalletService::class)->creditDeposit($otherUser, '999.00', 'deposit:other');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/wallet/ledger');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('70.00', $response->json('data.0.amount'));
    }
}
