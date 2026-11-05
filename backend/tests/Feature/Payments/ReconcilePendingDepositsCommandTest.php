<?php

namespace Tests\Feature\Payments;

use App\Models\DepositOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcilePendingDepositsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.alpha_upi.webhook_secret' => 'test-webhook-secret']);
    }

    public function test_it_credits_a_pending_order_past_the_grace_window_when_the_gateway_reports_success(): void
    {
        $user = User::factory()->create();
        $order = DepositOrder::create([
            'user_id' => $user->id,
            'amount' => '50.00',
            'our_order_id' => 'ORD-OLD',
            'provider' => 'alpha_upi',
            'status' => 'pending',
        ]);
        $order->forceFill(['created_at' => now()->subMinutes(15)])->save();

        Http::fake(['alphaupi.site/api/check-status' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => $order->our_order_id, 'payment_status' => 'success', 'amount' => 50.00, 'utr' => 'UTR-RECON'],
        ], 200)]);

        $this->artisan('payments:reconcile-pending-deposits')->assertSuccessful();

        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('50.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_it_leaves_a_recent_pending_order_inside_the_grace_window_untouched(): void
    {
        $user = User::factory()->create();
        $order = DepositOrder::create([
            'user_id' => $user->id,
            'amount' => '50.00',
            'our_order_id' => 'ORD-RECENT',
            'provider' => 'alpha_upi',
            'status' => 'pending',
        ]);
        $order->forceFill(['created_at' => now()->subMinutes(2)])->save();

        Http::fake(); // any call here would be unexpected — the order is too young to reconcile

        $this->artisan('payments:reconcile-pending-deposits')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_running_it_twice_does_not_double_credit(): void
    {
        $user = User::factory()->create();
        $order = DepositOrder::create([
            'user_id' => $user->id,
            'amount' => '50.00',
            'our_order_id' => 'ORD-TWICE',
            'provider' => 'alpha_upi',
            'status' => 'pending',
        ]);
        $order->forceFill(['created_at' => now()->subMinutes(15)])->save();

        Http::fake(['alphaupi.site/api/check-status' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => $order->our_order_id, 'payment_status' => 'success', 'amount' => 50.00, 'utr' => 'UTR-TWICE'],
        ], 200)]);

        $this->artisan('payments:reconcile-pending-deposits')->assertSuccessful();
        $this->artisan('payments:reconcile-pending-deposits')->assertSuccessful();

        $this->assertSame('50.00', $user->fresh()->wallet->deposit_balance);
    }
}
