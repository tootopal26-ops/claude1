<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Provider abstraction. The rest of the app only ever talks to this interface,
 * so Alpha UPI can be replaced by another gateway by writing one new adapter.
 *
 * Rules every adapter MUST follow:
 *  - never trust the browser: payment success is only ever established by a
 *    verified webhook (handleWebhook) or a server-to-server status lookup
 *    (verifyPayment / getPaymentStatus);
 *  - never log or return API keys / webhook secrets;
 *  - report amounts in integer PAISE.
 */
interface PaymentGateway
{
    /** Stable provider key stored in payments.provider, e.g. "alpha_upi". */
    public function provider(): string;

    /** Create a payment session at the gateway. */
    public function createPayment(CreatePaymentRequest $request): CreatePaymentResult;

    /** Ask the gateway for the current state of a payment (read-only). */
    public function getPaymentStatus(string $gatewayOrderId): PaymentStatusResult;

    /**
     * Authoritative check: is this payment really paid, and for exactly this
     * amount? Used before fulfilling anything that did not come from a signed webhook.
     */
    public function verifyPayment(string $gatewayOrderId, int $expectedAmountPaise): PaymentStatusResult;

    /**
     * Authenticate and normalise an inbound webhook. MUST verify the signature
     * and report `signatureValid=false` instead of throwing for forged requests.
     *
     * @param array<string,string> $headers lower-cased header names
     */
    public function handleWebhook(string $rawBody, array $headers): WebhookEvent;

    /** Refund through the gateway API (adapters without an API return supported=false). */
    public function refundPayment(RefundRequest $request): RefundResult;

    public function supportsRefunds(): bool;

    /** Non-destructive connectivity / credential probe for the admin "Test" button. */
    public function testConnection(): ConnectionTestResult;
}
