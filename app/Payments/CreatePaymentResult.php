<?php

declare(strict_types=1);

namespace App\Payments;

final class CreatePaymentResult
{
    /** @param array<string,mixed> $raw sanitised gateway response (safe to store) */
    public function __construct(
        public readonly bool $ok,
        /** Hosted payment page URL. */
        public readonly ?string $paymentUrl = null,
        /** What the QR code encodes (the payment URL for Alpha UPI). */
        public readonly ?string $qrPayload = null,
        public readonly string $message = '',
        public readonly string $code = '',
        /** Safe to show to the customer as-is? */
        public readonly bool $customerSafeMessage = false,
        /** Worth retrying later (network trouble) vs. a definite refusal. */
        public readonly bool $retryable = false,
        public readonly array $raw = [],
    ) {
    }
}
