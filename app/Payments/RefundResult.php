<?php

declare(strict_types=1);

namespace App\Payments;

final class RefundResult
{
    public function __construct(
        /** false when the gateway has no refund API: the admin must refund outside the app. */
        public readonly bool $supported,
        public readonly bool $ok = false,
        public readonly ?string $reference = null,
        public readonly string $message = '',
    ) {
    }
}
