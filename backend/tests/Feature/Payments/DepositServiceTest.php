<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\DepositService;
use App\Domain\Payments\PaymentGatewayException;
use App\Models\DepositOrder;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DepositServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The PaymentGatewayInterface binding is a singleton resolved lazily
        // on first use, so this must be set before any app(DepositService::class)
        // call in a test — otherwise the adapter sees an empty webhook secret
        // and rejects every signature.
        config(['services.alpha_upi.webhook_secret' => 'test-webhook-secret']);
    }

    private function fakeCreateOrderSuccess(?string $orderId = null): void
    {
        Http::fake(['alphaupi.site/api/create-order' => Http::response([
            'status' => 'success',
            'data' => [
                'payment_url' => 'https://alphaupi.site/pay/token123',
                'order_id' => $orderId ?? 'whatever',
                'token' => 'token123',
                'amount' => 50.00,
                'expires_at' => '2026-06-06 11:15:30',
            ],
        ], 201)]);
    }

    public function test_initiating_a_deposit_creates_a_pending_order_with_the_payment_url(): void
    {
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();

        $order = app(DepositService::class)->initiate($user, '50.00');

        $this->assertSame('pending', $order->status);
        $this->assertSame('50.00', $order->amount);
        $this->assertSame('https://alphaupi.site/pay/token123', $order->payment_url);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deposit.initiated']);
    }

    public function test_initiating_a_non_positive_amount_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(DepositService::class)->initiate($user, '0.00');
    }

    public function test_a_gateway_failure_marks_the_order_failed_and_raises_a_validation_error(): void
    {
        Http::fake(['alphaupi.site/api/create-order' => Http::response('Server error', 500)]);
        $user = User::factory()->create();

        try {
            app(DepositService::class)->initiate($user, '50.00');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('failed', DepositOrder::where('user_id', $user->id)->first()->status);
    }

    public function test_a_gateway_connection_failure_marks_the_order_failed_and_raises_a_validation_error(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host.');
        });
        $user = User::factory()->create();

        try {
            app(DepositService::class)->initiate($user, '50.00');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected — a transport-level failure must not leak as an
            // uncaught 500/stack trace, same as an application-level one.
        }

        $this->assertSame('failed', DepositOrder::where('user_id', $user->id)->first()->status);
    }

    public function test_reconcile_returns_the_orders_current_state_when_the_gateway_is_unreachable(): void
    {
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '50.00');

        Http::fake(function () {
            throw new ConnectionException('Connection timed out.');
        });

        $reconciled = app(DepositService::class)->reconcile($order);

        $this->assertSame('pending', $reconciled->status);
        $this->assertSame('0.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_a_valid_webhook_credits_the_wallet_exactly_once(): void
    {
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '50.00');

        $payload = json_encode([
            'event' => 'payment.success',
            'order_id' => $order->our_order_id,
            'amount' => 50.00,
            'utr' => 'UTR12345',
            'status' => 'success',
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('services.alpha_upi.webhook_secret'));

        app(DepositService::class)->handleWebhook($payload, $signature);

        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('UTR12345', $order->fresh()->utr);
        $this->assertSame('50.00', $user->fresh()->wallet->deposit_balance);
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());

        // Re-delivering the exact same event must not double-credit.
        app(DepositService::class)->handleWebhook($payload, $signature);
        $this->assertSame('50.00', $user->fresh()->wallet->deposit_balance);
        $this->assertDatabaseHas('risk_alerts', ['type' => 'duplicate_webhook', 'risk_level' => 'low', 'target_user_id' => $user->id]);
    }

    public function test_an_invalid_signature_is_rejected_and_changes_nothing(): void
    {
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '50.00');

        $payload = json_encode(['event' => 'payment.success', 'order_id' => $order->our_order_id, 'amount' => 50.00]);

        $this->expectException(PaymentGatewayException::class);
        app(DepositService::class)->handleWebhook($payload, 'wrong-signature');
    }

    public function test_a_webhook_for_an_unrecognized_order_is_silently_ignored(): void
    {
        $payload = json_encode(['event' => 'payment.success', 'order_id' => 'UNKNOWN', 'amount' => 50.00]);
        $signature = hash_hmac('sha256', $payload, (string) config('services.alpha_upi.webhook_secret'));

        app(DepositService::class)->handleWebhook($payload, $signature);

        $this->assertSame(0, DepositOrder::count());
    }

    public function test_an_amount_mismatch_raises_a_risk_alert_but_still_credits_our_own_recorded_amount(): void
    {
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '50.00');

        $payload = json_encode([
            'event' => 'payment.success',
            'order_id' => $order->our_order_id,
            'amount' => 999.00, // tampered/mismatched amount
            'utr' => 'UTR12345',
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('services.alpha_upi.webhook_secret'));

        app(DepositService::class)->handleWebhook($payload, $signature);

        $this->assertSame('50.00', $user->fresh()->wallet->deposit_balance);
        $this->assertDatabaseHas('risk_alerts', ['type' => 'deposit_amount_mismatch']);
    }

    public function test_reconcile_credits_the_wallet_when_check_status_reports_success(): void
    {
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '50.00');

        Http::fake(['alphaupi.site/api/check-status' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => $order->our_order_id, 'payment_status' => 'success', 'amount' => 50.00, 'utr' => 'UTR999'],
        ], 200)]);

        $reconciled = app(DepositService::class)->reconcile($order);

        $this->assertSame('success', $reconciled->status);
        $this->assertSame('50.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_reconcile_marks_the_order_failed_when_check_status_reports_failed(): void
    {
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '50.00');

        Http::fake(['alphaupi.site/api/check-status' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => $order->our_order_id, 'payment_status' => 'failed', 'amount' => 50.00],
        ], 200)]);

        $reconciled = app(DepositService::class)->reconcile($order);

        $this->assertSame('failed', $reconciled->status);
        $this->assertSame('0.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_reconcile_does_not_call_the_gateway_for_an_already_resolved_order(): void
    {
        $this->fakeCreateOrderSuccess();
        $user = User::factory()->create();
        $order = app(DepositService::class)->initiate($user, '50.00');
        $order->status = 'success';
        $order->save();

        Http::fake(); // any call here would be unexpected

        app(DepositService::class)->reconcile($order);

        Http::assertNothingSent();
    }

    /**
     * This is the one test in the suite that deliberately does NOT use
     * the sqlite in-memory connection PHPUnit otherwise runs under: an
     * in-process test can only ever hold a single DB connection, so it
     * can't actually exercise several webhook deliveries racing for the
     * same payment_webhook_events unique constraint. Instead it spawns
     * several real, independent PHP processes against the shared
     * `minimax_testing` MySQL database (each gets its own connection) —
     * exactly what a payment gateway's retried/duplicated delivery looks
     * like in production — and proves the wallet is still credited
     * exactly once.
     */
    public function test_simultaneous_duplicate_webhook_deliveries_never_double_credit(): void
    {
        if (! Process::run('mysql --version')->successful()) {
            $this->markTestSkipped('mysql client not available to prepare the concurrency fixture.');
        }

        $secret = 'test-webhook-concurrency-secret';
        $env = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'minimax_testing', 'ALPHA_UPI_WEBHOOK_SECRET' => $secret];

        $migrate = Process::env($env)->path(base_path())->run('php artisan migrate:fresh --no-interaction');
        $this->assertTrue($migrate->successful(), $migrate->errorOutput());

        $seed = Process::env($env)->path(base_path())->run('php artisan app:seed-deposit-concurrency-fixture 50.00');
        $this->assertTrue($seed->successful(), $seed->errorOutput());
        $fixture = json_decode(trim($seed->output()), true, flags: JSON_THROW_ON_ERROR);
        $userId = $fixture['user_id'];
        $orderId = $fixture['our_order_id'];

        $deliveries = 8;
        $results = Process::pool(function ($pool) use ($env, $orderId, $deliveries) {
            for ($i = 0; $i < $deliveries; $i++) {
                $pool->as((string) $i)
                    ->env($env)
                    ->path(base_path())
                    ->command("php artisan app:attempt-webhook-delivery {$orderId} 50.00");
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

        $ledgerEntryCount = DB::connection('concurrency_check')
            ->table('wallet_ledger_entries')->where('user_id', $userId)->count();
        $this->assertSame(1, $ledgerEntryCount, 'Exactly one of the simultaneous duplicate webhook deliveries should credit the wallet.');

        $webhookEventCount = DB::connection('concurrency_check')
            ->table('payment_webhook_events')->where('order_id', $orderId)->count();
        $this->assertSame(1, $webhookEventCount, 'The unique constraint must dedup every delivery down to one event row.');

        $finalBalance = DB::connection('concurrency_check')
            ->table('wallets')->where('user_id', $userId)->value('deposit_balance');
        $this->assertSame('50.00', $finalBalance, 'The wallet must be credited exactly once, not once per duplicate delivery.');
    }
}
