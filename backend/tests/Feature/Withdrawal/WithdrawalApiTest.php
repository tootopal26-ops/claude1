<?php

namespace Tests\Feature\Withdrawal;

use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalApiTest extends TestCase
{
    use RefreshDatabase;

    private function fundWinnings(User $user, string $amount): void
    {
        app(WalletService::class)->creditWinnings($user, $amount, 'seed:'.$user->id);
    }

    public function test_a_player_can_request_a_withdrawal(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', ['amount' => '40.00', 'upi_id' => 'player@upi']);

        $response->assertCreated()
            ->assertJsonPath('data.amount', '40.00')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_requesting_more_than_available_returns_a_validation_error(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '10.00');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', ['amount' => '40.00', 'upi_id' => 'player@upi']);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_an_invalid_upi_id_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->fundWinnings($user, '100.00');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/withdrawals', ['amount' => '40.00', 'upi_id' => 'not-a-upi-id']);

        $response->assertStatus(422)->assertJsonValidationErrors('upi_id');
    }

    public function test_a_player_only_sees_their_own_withdrawal_history(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->fundWinnings($user, '100.00');
        $this->fundWinnings($other, '100.00');

        WithdrawalRequest::create(['user_id' => $user->id, 'amount' => '10.00', 'upi_id' => 'mine@upi', 'status' => 'pending']);
        WithdrawalRequest::create(['user_id' => $other->id, 'amount' => '20.00', 'upi_id' => 'theirs@upi', 'status' => 'pending']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/withdrawals');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.upi_id', 'mine@upi');
    }
}
