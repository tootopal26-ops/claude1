<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\DepositBonusService;
use App\Domain\Payments\DepositService;
use App\Models\DepositBonusAward;
use App\Models\DepositOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DepositBonusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.alpha_upi.webhook_secret' => 'test-webhook-secret']);
    }

    private function fakeCreateOrderSuccess(): void
    {
        Http::fake(['alphaupi.site/api/create-order' => Http::response([
            'status' => 'success',
            'data' => [
                'payment_url' => 'https://alphaupi.site/pay/token123',
                'order_id' => 'whatever',
                'token' => 'token123',
                'amount' => 100.00,
                'expires_at' => '2026-06-06 11:15:30',
            ],
        ], 201)]);
    }

    private function deliverWebhook(DepositOrder $order, string $amount): void
    {
        $payload = json_encode([
            'event' => 'payment.success',
            'order_id' => $order->our_order_id,
            'amount' => (float) $amount,
            'utr' => 'UTR'.$order->id,
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('services.alpha_upi.webhook_secret'));

        app(DepositService::class)->handleWebhook($payload, $signature);
    }

    public function test_a_first_eligible_deposit_is_awarded_the_bonus(): void
    {
        config(['minimax.deposit_bonus_enabled' => true, 'minimax.deposit_bonus_amount' => '50.00', 'minimax.deposit_bonus_min_deposit' => '50.00']);
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '100.00');

        $this->deliverWebhook($order, '100.00');

        $this->assertSame('150.00', $user->fresh()->wallet->deposit_balance); // 100 deposit + 50 bonus
        $this->assertDatabaseHas('deposit_bonus_awards', ['user_id' => $user->id, 'deposit_order_id' => $order->id, 'amount' => '50.00']);
    }

    public function test_a_second_deposit_under_first_deposit_only_is_not_awarded_again(): void
    {
        config(['minimax.deposit_bonus_enabled' => true, 'minimax.deposit_bonus_amount' => '50.00', 'minimax.deposit_bonus_first_deposit_only' => true]);
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();

        $first = app(DepositService::class)->initiate($user, '100.00');
        $this->deliverWebhook($first, '100.00');

        $second = app(DepositService::class)->initiate($user, '100.00');
        $this->deliverWebhook($second, '100.00');

        $this->assertSame(1, DepositBonusAward::where('user_id', $user->id)->count());
        $this->assertSame('250.00', $user->fresh()->wallet->deposit_balance); // 100 + 50 + 100, no second bonus
    }

    public function test_a_deposit_below_the_minimum_does_not_qualify(): void
    {
        config(['minimax.deposit_bonus_enabled' => true, 'minimax.deposit_bonus_amount' => '50.00', 'minimax.deposit_bonus_min_deposit' => '100.00']);
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '50.00');

        $this->deliverWebhook($order, '50.00');

        $this->assertSame(0, DepositBonusAward::count());
        $this->assertSame('50.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_the_bonus_system_disabled_awards_nothing(): void
    {
        config(['minimax.deposit_bonus_enabled' => false, 'minimax.deposit_bonus_amount' => '50.00']);
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '100.00');

        $this->deliverWebhook($order, '100.00');

        $this->assertSame(0, DepositBonusAward::count());
        $this->assertSame('100.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_a_duplicate_webhook_delivery_never_double_credits_the_bonus(): void
    {
        config(['minimax.deposit_bonus_enabled' => true, 'minimax.deposit_bonus_amount' => '50.00']);
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '100.00');

        $this->deliverWebhook($order, '100.00');
        $this->deliverWebhook($order, '100.00'); // duplicate delivery of the same event

        $this->assertSame(1, DepositBonusAward::where('deposit_order_id', $order->id)->count());
        $this->assertSame('150.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_a_pending_order_that_never_receives_a_webhook_is_never_awarded(): void
    {
        config(['minimax.deposit_bonus_enabled' => true, 'minimax.deposit_bonus_amount' => '50.00']);
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        app(DepositService::class)->initiate($user, '100.00');

        $this->assertSame(0, DepositBonusAward::count());
        $this->assertSame('0.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_an_admin_changing_the_amount_only_affects_later_awards(): void
    {
        config(['minimax.deposit_bonus_enabled' => true, 'minimax.deposit_bonus_amount' => '50.00', 'minimax.deposit_bonus_first_deposit_only' => false]);
        $this->fakeCreateOrderSuccess();
        $userA = User::factory()->create();
        $orderA = app(DepositService::class)->initiate($userA, '100.00');
        $this->deliverWebhook($orderA, '100.00');

        config(['minimax.deposit_bonus_amount' => '75.00']);

        $userB = User::factory()->create();
        $orderB = app(DepositService::class)->initiate($userB, '100.00');
        $this->deliverWebhook($orderB, '100.00');

        $this->assertSame('50.00', (string) DepositBonusAward::where('user_id', $userA->id)->first()->amount);
        $this->assertSame('75.00', (string) DepositBonusAward::where('user_id', $userB->id)->first()->amount);
    }

    public function test_the_lifetime_cap_is_enforced_when_first_deposit_only_is_off(): void
    {
        config([
            'minimax.deposit_bonus_enabled' => true,
            'minimax.deposit_bonus_amount' => '50.00',
            'minimax.deposit_bonus_first_deposit_only' => false,
            'minimax.deposit_bonus_max_per_player' => '75.00',
        ]);
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();

        $first = app(DepositService::class)->initiate($user, '100.00');
        $this->deliverWebhook($first, '100.00'); // awards 50, total 50 <= 75

        $second = app(DepositService::class)->initiate($user, '100.00');
        $this->deliverWebhook($second, '100.00'); // would bring total to 100 > 75 cap — skipped

        $this->assertSame(1, DepositBonusAward::where('user_id', $user->id)->count());
        $this->assertSame(0, bccomp('50.00', (string) DepositBonusAward::where('user_id', $user->id)->sum('amount'), 2));
    }

    public function test_eligibility_for_reflects_current_settings_and_claim_state(): void
    {
        config(['minimax.deposit_bonus_enabled' => true, 'minimax.deposit_bonus_amount' => '50.00', 'minimax.deposit_bonus_min_deposit' => '10.00', 'minimax.deposit_bonus_first_deposit_only' => true]);
        $user = User::factory()->create();

        $before = app(DepositBonusService::class)->eligibilityFor($user);
        $this->assertTrue($before['eligible']);

        $this->fakeCreateOrderSuccess();
        $order = app(DepositService::class)->initiate($user, '100.00');
        $this->deliverWebhook($order, '100.00');

        $after = app(DepositBonusService::class)->eligibilityFor($user->fresh());
        $this->assertFalse($after['eligible']);
    }
}
