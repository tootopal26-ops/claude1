<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\AlphaUpiAdapter;
use App\Domain\Payments\PaymentGatewayException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlphaUpiAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function adapter(): AlphaUpiAdapter
    {
        return new AlphaUpiAdapter(
            'https://alphaupi.site/api',
            'test-key',
            'test-secret',
            'test-webhook-secret',
            'X-PayIndia-Signature',
        );
    }

    public function test_create_order_returns_the_payment_url_and_token_on_success(): void
    {
        Http::fake(['alphaupi.site/api/create-order' => Http::response([
            'status' => 'success',
            'message' => 'Order created successfully',
            'data' => [
                'payment_url' => 'https://alphaupi.site/pay/abc123',
                'order_id' => 'ORD1',
                'token' => 'abc123',
                'amount' => 100.00,
                'currency' => 'INR',
                'customer_name' => 'Test Player',
                'expires_at' => '2026-06-06 11:15:30',
                'created_at' => '2026-06-05 11:15:30',
            ],
        ], 201)]);

        $result = $this->adapter()->createOrder('ORD1', '100.00', 'Test Player');

        $this->assertSame('https://alphaupi.site/pay/abc123', $result['payment_url']);
        $this->assertSame('abc123', $result['token']);
        $this->assertSame('2026-06-06 11:15:30', $result['expires_at']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://alphaupi.site/api/create-order'
                && $request->hasHeader('X-API-Key', 'test-key')
                && $request->hasHeader('X-API-Secret', 'test-secret')
                && $request['order_id'] === 'ORD1';
        });
    }

    public function test_create_order_throws_on_a_failed_http_response(): void
    {
        Http::fake(['alphaupi.site/api/create-order' => Http::response('Server error', 500)]);

        $this->expectException(PaymentGatewayException::class);
        $this->adapter()->createOrder('ORD1', '100.00', 'Test Player');
    }

    public function test_create_order_throws_when_the_response_status_is_not_success(): void
    {
        Http::fake(['alphaupi.site/api/create-order' => Http::response(['status' => 'error', 'error' => 'Invalid amount'], 200)]);

        $this->expectException(PaymentGatewayException::class);
        $this->adapter()->createOrder('ORD1', '100.00', 'Test Player');
    }

    public function test_check_status_returns_the_payment_details_on_success(): void
    {
        Http::fake(['alphaupi.site/api/check-status' => Http::response([
            'status' => 'success',
            'data' => [
                'order_id' => 'ORD1',
                'amount' => 100.00,
                'currency' => 'INR',
                'payment_status' => 'success',
                'utr' => '615372849102',
                'gateway_txn_id' => 'TXN_9201938561',
                'paid_at' => '2026-06-05 11:16:45',
            ],
        ], 200)]);

        $result = $this->adapter()->checkStatus('ORD1');

        $this->assertSame('success', $result['payment_status']);
        $this->assertSame('100.00', $result['amount']);
        $this->assertSame('615372849102', $result['utr']);
        $this->assertSame('TXN_9201938561', $result['gateway_txn_id']);
    }

    public function test_webhook_signature_header_returns_the_configured_header_name(): void
    {
        $this->assertSame('X-PayIndia-Signature', $this->adapter()->webhookSignatureHeader());
    }

    public function test_verify_webhook_signature_accepts_a_matching_hmac(): void
    {
        $payload = '{"event":"payment.success","order_id":"ORD1"}';
        $signature = hash_hmac('sha256', $payload, 'test-webhook-secret');

        $this->assertTrue($this->adapter()->verifyWebhookSignature($payload, $signature));
    }

    public function test_verify_webhook_signature_rejects_a_wrong_signature(): void
    {
        $payload = '{"event":"payment.success","order_id":"ORD1"}';

        $this->assertFalse($this->adapter()->verifyWebhookSignature($payload, 'not-the-right-signature'));
    }

    public function test_verify_webhook_signature_rejects_an_empty_signature(): void
    {
        $this->assertFalse($this->adapter()->verifyWebhookSignature('{}', ''));
    }
}
