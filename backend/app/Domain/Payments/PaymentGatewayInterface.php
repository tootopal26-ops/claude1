<?php

namespace App\Domain\Payments;

/**
 * Contract every payment gateway adapter implements, so the rest of the
 * app (DepositService, the webhook route) never depends on a specific
 * provider's request/response shape.
 */
interface PaymentGatewayInterface
{
    public function providerName(): string;

    /**
     * @return array{payment_url: string, token: string, expires_at: ?string, raw: array<string, mixed>}
     *
     * @throws PaymentGatewayException
     */
    public function createOrder(
        string $orderId,
        string $amount,
        string $customerName,
        ?string $customerMobile = null,
        ?string $description = null,
    ): array;

    /**
     * @return array{payment_status: string, amount: ?string, utr: ?string, gateway_txn_id: ?string, paid_at: ?string, raw: array<string, mixed>}
     *
     * @throws PaymentGatewayException
     */
    public function checkStatus(string $orderId): array;

    /**
     * The HTTP header name this provider's webhook signature arrives
     * under. Exposed so the webhook route knows which header to read
     * without hardcoding a provider-specific name.
     */
    public function webhookSignatureHeader(): string;

    public function verifyWebhookSignature(string $rawPayload, string $signature): bool;
}
