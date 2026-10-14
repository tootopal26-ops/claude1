<?php

declare(strict_types=1);

namespace App\Payments;

final class CreatePaymentRequest
{
    public function __construct(
        /** Our unique id for this payment at the gateway. */
        public readonly string $gatewayOrderId,
        public readonly int $amountPaise,
        public readonly string $redirectUrl,
        public readonly string $remark1 = '',
        public readonly string $remark2 = '',
        public readonly string $customerName = '',
        public readonly string $customerMobile = '',
    ) {
    }
}
