<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical status vocabulary + the order state machine.
 * Statuses are plain strings in the DB; every change goes through
 * App\Services\Orders::transition() which enforces canTransition().
 */
final class Status
{
    // ── order statuses (spec §20) ────────────────────────────────────────────
    public const PENDING_PAYMENT = 'PENDING_PAYMENT';
    public const PAID            = 'PAID';
    public const PROCESSING      = 'PROCESSING';
    public const SUBMITTED       = 'SUBMITTED';
    public const IN_PROGRESS     = 'IN_PROGRESS';
    public const COMPLETED       = 'COMPLETED';
    public const PARTIAL         = 'PARTIAL';
    public const CANCELLED       = 'CANCELLED';
    public const REFUNDED        = 'REFUNDED';
    public const FAILED          = 'FAILED';
    public const EXPIRED         = 'EXPIRED';
    public const MANUAL_PENDING  = 'MANUAL_PENDING';
    public const MANUAL_APPROVED = 'MANUAL_APPROVED';

    public const ORDER_ALL = [
        self::PENDING_PAYMENT, self::PAID, self::PROCESSING, self::SUBMITTED, self::IN_PROGRESS,
        self::COMPLETED, self::PARTIAL, self::CANCELLED, self::REFUNDED, self::FAILED,
        self::EXPIRED, self::MANUAL_PENDING, self::MANUAL_APPROVED,
    ];

    /** Orders that count as delivered. */
    public const FULFILLED = [self::COMPLETED, self::MANUAL_APPROVED];
    /** Paid orders still being worked on. */
    public const IN_FLIGHT = [self::PAID, self::PROCESSING, self::SUBMITTED, self::IN_PROGRESS];
    /** Nothing more will happen without an admin. */
    public const TERMINAL = [self::COMPLETED, self::MANUAL_APPROVED, self::CANCELLED, self::REFUNDED, self::EXPIRED];

    private const TRANSITIONS = [
        self::PENDING_PAYMENT => [self::PAID, self::MANUAL_PENDING, self::EXPIRED, self::FAILED, self::CANCELLED],
        self::PAID            => [self::PROCESSING, self::MANUAL_PENDING, self::FAILED, self::CANCELLED, self::REFUNDED],
        self::PROCESSING      => [self::SUBMITTED, self::PAID, self::FAILED, self::MANUAL_PENDING, self::CANCELLED],
        self::SUBMITTED       => [self::IN_PROGRESS, self::COMPLETED, self::PARTIAL, self::CANCELLED, self::FAILED, self::REFUNDED],
        self::IN_PROGRESS     => [self::COMPLETED, self::PARTIAL, self::CANCELLED, self::FAILED, self::REFUNDED],
        self::MANUAL_PENDING  => [self::MANUAL_APPROVED, self::COMPLETED, self::CANCELLED, self::REFUNDED, self::FAILED],
        self::MANUAL_APPROVED => [self::COMPLETED, self::REFUNDED, self::CANCELLED],
        self::COMPLETED       => [self::REFUNDED],
        self::PARTIAL         => [self::COMPLETED, self::REFUNDED],
        self::FAILED          => [self::PAID, self::PROCESSING, self::MANUAL_PENDING, self::REFUNDED, self::CANCELLED],
        self::CANCELLED       => [self::REFUNDED],
        self::EXPIRED         => [self::PAID, self::MANUAL_PENDING],   // a payment can still land late
        self::REFUNDED        => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function nextStatuses(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function orderLabel(string $s): string
    {
        return match ($s) {
            self::PENDING_PAYMENT => 'Pending payment',
            self::PAID            => 'Paid',
            self::PROCESSING      => 'Processing',
            self::SUBMITTED       => 'Submitted',
            self::IN_PROGRESS     => 'In progress',
            self::COMPLETED       => 'Completed',
            self::PARTIAL         => 'Partial',
            self::CANCELLED       => 'Cancelled',
            self::REFUNDED        => 'Refunded',
            self::FAILED          => 'Failed',
            self::EXPIRED         => 'Expired',
            self::MANUAL_PENDING  => 'Manual pending',
            self::MANUAL_APPROVED => 'Manual approved',
            default               => $s,
        };
    }

    /** What the CUSTOMER sees (no internal jargon). */
    public static function customerLabel(string $s, string $mode = 'AUTO'): string
    {
        return match ($s) {
            self::PENDING_PAYMENT => 'Waiting for payment',
            self::PAID, self::PROCESSING => 'Payment received — starting your order',
            self::SUBMITTED       => 'Order placed',
            self::IN_PROGRESS     => 'In progress',
            self::MANUAL_PENDING  => 'Payment received — being processed by our team',
            self::MANUAL_APPROVED => 'Approved — delivery in progress',
            self::COMPLETED       => 'Completed',
            self::PARTIAL         => 'Partially completed',
            self::CANCELLED       => 'Cancelled',
            self::REFUNDED        => 'Refunded',
            self::FAILED          => 'Needs attention — our team has been notified',
            self::EXPIRED         => 'Payment session expired',
            default               => $s,
        };
    }

    // ── payment statuses ─────────────────────────────────────────────────────
    public const PAY_CREATING           = 'creating';
    public const PAY_PENDING            = 'pending';
    public const PAY_SUCCESS            = 'success';
    public const PAY_FAILED             = 'failed';
    public const PAY_EXPIRED            = 'expired';
    public const PAY_MISMATCH           = 'mismatch';     // gateway reported a different amount → needs a human
    public const PAY_REFUNDED           = 'refunded';
    public const PAY_PART_REFUNDED      = 'partially_refunded';

    /** Payment states in which money was actually received. */
    public const PAY_RECEIVED = [self::PAY_SUCCESS, self::PAY_REFUNDED, self::PAY_PART_REFUNDED];

    // ── service modes / types ────────────────────────────────────────────────
    public const AUTO   = 'AUTO';
    public const MANUAL = 'MANUAL';

    public const SERVICE_TYPES = ['followers', 'likes', 'views', 'verification', 'subscribers', 'watch_time'];
    public const TARGET_KINDS  = ['profile', 'post', 'video', 'channel'];
    public const BADGE_COLORS  = ['red', 'green', 'blue', 'amber', 'pink', 'purple', 'gray'];

    public static function serviceTypeLabel(string $t): string
    {
        return match ($t) {
            'followers'    => 'Followers',
            'likes'        => 'Likes',
            'views'        => 'Views',
            'verification' => 'Verification',
            'subscribers'  => 'Subscribers',
            'watch_time'   => 'Watch Time',
            default        => ucfirst($t),
        };
    }

    // ── provider (SMM panel) status → internal order status ──────────────────
    public static function fromProviderStatus(string $providerStatus): ?string
    {
        return match (strtolower(trim($providerStatus))) {
            'pending'                         => self::SUBMITTED,
            'in progress', 'processing', 'inprogress' => self::IN_PROGRESS,
            'completed', 'complete'           => self::COMPLETED,
            'partial'                         => self::PARTIAL,
            'canceled', 'cancelled'           => self::CANCELLED,
            'fail', 'failed', 'error'         => self::FAILED,
            default                           => null,
        };
    }
}
