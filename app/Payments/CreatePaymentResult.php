<?php

declare(strict_types=1);

namespace App\Payments;

final class CreatePaymentResult
{
    /** @param array<string,mixed> $raw sanitised gateway response (safe to store) */
    public function __construct(
        public readonly bool $ok,
        /** The gateway's hosted checkout page: where the customer is sent to pay. Never encoded into a QR code (see $upiIntent). */
        public readonly ?string $paymentUrl = null,
        /**
         * A UPI payment intent (`upi://pay?pa=…&am=…&cu=INR…`) for exactly this order — set only when the gateway, or the checkout page it
         * hosts (App\Payments\HostedCheckout), actually provides one. It is the only thing the payment page may draw as a QR code: a UPI app
         * that scans it opens its payment screen with the payee and the amount filled in. Orders re-validates it (App\Support\Upi::validateFor)
         * against the exact amount before it is stored; null means "no QR" and the customer is sent to the hosted page.
         */
        public readonly ?string $upiIntent = null,
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
