<?php

namespace Tests\Feature\Payments;

use App\Models\DepositOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DepositApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.alpha_upi.webhook_secret' => 'test-webhook-secret']);
    }

    public function test_a_player_can_start_a_deposit(): void
    {
        Http::fake(['alphaupi.site/api/create-order' => Http::response([
            'status' => 'success',
            'data' => ['payment_url' => 'https://alphaupi.site/pay/tok', 'token' => 'tok', 'amount' => 50.00],
        ], 201)]);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/deposits', ['amount' => '50.00']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_url', 'https://alphaupi.site/pay/tok');
    }

    public function test_a_non_positive_amount_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/deposits', ['amount' => '0']);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_a_player_only_sees_their_own_deposit_history(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        DepositOrder::create(['user_id' => $user->id, 'amount' => '10.00', 'our_order_id' => 'MINE1', 'provider' => 'alpha_upi', 'status' => 'pending']);
        DepositOrder::create(['user_id' => $other->id, 'amount' => '20.00', 'our_order_id' => 'THEIRS1', 'provider' => 'alpha_upi', 'status' => 'pending']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/deposits');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_player_cannot_view_another_players_deposit(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $deposit = DepositOrder::create(['user_id' => $other->id, 'amount' => '20.00', 'our_order_id' => 'THEIRS2', 'provider' => 'alpha_upi', 'status' => 'success']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/deposits/{$deposit->id}");

        $response->assertForbidden();
    }

    public function test_viewing_a_pending_deposit_reconciles_it_against_the_gateway(): void
    {
        $user = User::factory()->create();
        $deposit = DepositOrder::create(['user_id' => $user->id, 'amount' => '20.00', 'our_order_id' => 'ORD-RECON', 'provider' => 'alpha_upi', 'status' => 'pending']);

        Http::fake(['alphaupi.site/api/check-status' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'ORD-RECON', 'payment_status' => 'success', 'amount' => 20.00, 'utr' => 'UTRXYZ'],
        ], 200)]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/deposits/{$deposit->id}");

        $response->assertOk()->assertJsonPath('data.status', 'success');
        $this->assertSame('20.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_the_webhook_endpoint_accepts_a_correctly_signed_payload(): void
    {
        $user = User::factory()->create();
        $deposit = DepositOrder::create(['user_id' => $user->id, 'amount' => '30.00', 'our_order_id' => 'ORD-WEBHOOK', 'provider' => 'alpha_upi', 'status' => 'pending']);

        $payload = ['event' => 'payment.success', 'order_id' => 'ORD-WEBHOOK', 'amount' => 30.00, 'utr' => 'UTRWEBHOOK', 'status' => 'success'];
        $signature = hash_hmac('sha256', json_encode($payload), 'test-webhook-secret');

        $response = $this->withHeaders(['X-PayIndia-Signature' => $signature])
            ->postJson('/api/webhooks/alpha-upi', $payload);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('success', $deposit->fresh()->status);
        $this->assertSame('30.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_the_webhook_endpoint_rejects_an_incorrectly_signed_payload(): void
    {
        $payload = ['event' => 'payment.success', 'order_id' => 'ORD-X', 'amount' => 30.00];

        $response = $this->withHeaders(['X-PayIndia-Signature' => 'bogus'])
            ->postJson('/api/webhooks/alpha-upi', $payload);

        $response->assertStatus(400);
    }
}
