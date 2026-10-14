<?php

declare(strict_types=1);

namespace App\Payments;

final class WebhookEvent
{
    /** @param array<string,mixed> $payload decoded body */
    public function __construct(
        public readonly bool $signatureValid,
        public readonly ?string $eventId = null,
        public readonly string $status = PaymentStatusResult::UNKNOWN,
        public readonly ?string $gatewayOrderId = null,
        public readonly ?int $amountPaise = null,
        public readonly ?string $utr = null,
        public readonly ?string $method = null,
        public readonly ?int $paidAt = null,
        public readonly array $payload = [],
        public readonly string $error = '',
    ) {
    }
}
