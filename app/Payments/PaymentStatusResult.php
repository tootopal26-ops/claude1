<?php

declare(strict_types=1);

namespace App\Payments;

final class PaymentStatusResult
{
    public const PENDING = 'PENDING';
    public const SUCCESS = 'SUCCESS';
    public const FAILED  = 'FAILED';
    public const EXPIRED = 'EXPIRED';
    public const UNKNOWN = 'UNKNOWN';

    /** @param array<string,mixed> $raw */
    public function __construct(
        /** Could we talk to the gateway at all? false = network/API trouble, retry later. */
        public readonly bool $reachable,
        public readonly string $status = self::UNKNOWN,
        public readonly ?int $amountPaise = null,
        public readonly ?string $utr = null,
        public readonly ?string $method = null,
        /** Gateway-reported payment time (unix seconds), when provided. */
        public readonly ?int $paidAt = null,
        public readonly string $message = '',
        public readonly array $raw = [],
        /** Currency the gateway says the payment is in (upper-case code), null when it does not say. The shop is INR-only. */
        public readonly ?string $currency = null,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->reachable && $this->status === self::SUCCESS;
    }
}
