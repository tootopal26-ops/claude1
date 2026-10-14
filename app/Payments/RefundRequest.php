<?php

declare(strict_types=1);

namespace App\Payments;

final class RefundRequest
{
    public function __construct(
        public readonly string $gatewayOrderId,
        public readonly int $amountPaise,
        public readonly string $reason = '',
    ) {
    }
}
