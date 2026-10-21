<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Payments\CreatePaymentRequest;
use App\Payments\Gateways;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Status;
use App\Support\Targets;
use App\Support\Tz;
use App\Support\Upi;

/**
 * Order lifecycle: checkout, the status state machine and its history.
 *
 * Invariants enforced here (never in the browser):
 *   - price, quantity, mode and cost come from the DB, never from the request;
 *   - every status change goes through transition() (validated + audited);
 *   - a payment session lives for exactly PAYMENT_TTL seconds.
 */
final class Orders
{
    public const PAYMENT_TTL = 300; // 5:00 — spec §13

    private const ID_ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; // no 0/O/1/I/L/U

    public static function newPublicId(): string
    {
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= self::ID_ALPHABET[random_int(0, strlen(self::ID_ALPHABET) - 1)];
        }
        return 'SMB-' . $s;
    }

    /**
     * Create a PENDING order plus its payment session and return what the
     * browser needs to open the payment page.
     *
     * @param array{service_id:mixed,target:mixed} $input
     * @param array{visitor_id:?int,session_id:?int} $visitor
     * @return array{ref:string,order_id:string,reused:bool}
     */
    public static function checkout(Request $req, array $input, array $visitor): array
    {
        $ip = $req->ip();
        RateLimiter::enforce('checkout-ip-min', $ip, 8, 600, 'You are creating orders too quickly. Please wait a few minutes.');
        RateLimiter::enforce('checkout-ip-hour', $ip, 40, 3600, 'Too many orders from your network. Please try again later.');

        if (Settings::site()['maintenance']) {
            throw new HttpException(503, Settings::site()['maintenance_message'], 'maintenance');
        }

        $serviceId = $input['service_id'] ?? null;
        $rawTarget = $input['target'] ?? null;
        if (!(is_int($serviceId) || (is_string($serviceId) && ctype_digit($serviceId))) || !is_string($rawTarget)) {
            throw new HttpException(422, 'Please choose a service and enter your username or link.', 'invalid');
        }
        $service = Catalog::orderable((int) $serviceId, $req->cookie(Tracking::VISITOR_COOKIE));
        if ($service === null) {
            throw new HttpException(422, 'This service is not available right now. Please pick another one.', 'service_unavailable');
        }

        // Followers / Subscribers take the account LINK; the browser asks for it too, but only this check counts.
        $linkOnly = Targets::requiresLink((string) $service['platform_slug'], (string) $service['target_kind'], (string) $service['type']);
        $check = Targets::validate((string) $service['platform_slug'], (string) $service['target_kind'], $rawTarget, $linkOnly);
        if (!$check['ok']) {
            throw new HttpException(422, $check['error'], 'invalid_target', ['fields' => ['target' => $check['error']]]);
        }

        $gwRow = Gateways::activeRow();
        if ($gwRow === null) {
            Log::error('Checkout blocked: no active payment gateway is configured.');
            throw new HttpException(503, 'Payments are temporarily unavailable. Please try again soon.', 'payments_unavailable');
        }

        // One checkout at a time per visitor + service + link: concurrent requests (double submit, two tabs, a retry racing the
        // original) queue up here, so the second one finds the first one's payment session and reuses it instead of creating a twin.
        $lock = null;
        if ($visitor['visitor_id']) {
            $lock = 'co:' . $visitor['visitor_id'] . ':' . $service['id'] . ':' . substr(sha1((string) $check['url']), 0, 20);
            if (!Db::tryLock($lock, 10)) {
                throw new HttpException(409, 'Your previous request is still being processed. Please wait a moment and try again.', 'in_progress');
            }
        }
        try {
            return self::openSession($service, $rawTarget, $check, $gwRow, $visitor, $linkOnly);
        } finally {
            if ($lock !== null) {
                Db::unlock($lock);
            }
        }
    }

    /**
     * Reuse the visitor's live payment session for this exact order, or create the order + payment and ask the gateway for a QR.
     *
     * @param array<string,mixed> $service
     * @param array{ok:bool,url?:string,display?:string} $check
     * @param array<string,mixed> $gwRow
     * @param array{visitor_id:?int,session_id:?int} $visitor
     * @param bool $linkOnly the customer gave an account link (followers / subscribers): the validated link is what the order keeps as its target
     * @return array{ref:string,order_id:string,reused:bool}
     */
    private static function openSession(array $service, string $rawTarget, array $check, array $gwRow, array $visitor, bool $linkOnly): array
    {
        // Same visitor, same service, same target, a still-valid unpaid session → reuse it (no duplicate orders).
        if ($visitor['visitor_id']) {
            $existing = Db::one(
                "SELECT o.public_id, p.ref FROM orders o JOIN payments p ON p.order_id = o.id "
                . "WHERE o.visitor_id = ? AND o.service_id = ? AND o.target_url = ? AND o.status = 'PENDING_PAYMENT' "
                . "AND p.status = 'pending' AND p.expires_at > ? ORDER BY o.id DESC LIMIT 1",
                [$visitor['visitor_id'], $service['id'], $check['url'], Clock::sql(Clock::now() + 30)]
            );
            if ($existing) {
                return ['ref' => (string) $existing['ref'], 'order_id' => (string) $existing['public_id'], 'reused' => true];
            }
        }

        $now = Clock::now();
        $auto = $service['mode'] === Status::AUTO;
        $day = Tz::today();
        $ref = Crypto::token(16);
        $gatewayOrderId = 'SMB' . strtoupper(bin2hex(random_bytes(9)));

        // What the order shows as its target: for an account link the complete validated URL (https://www.instagram.com/username/),
        // otherwise what the customer typed. `target_url` is always the normalised URL — the one the SMM provider receives.
        $shownTarget = $linkOnly ? (string) $check['url'] : mb_substr(Targets::clean($rawTarget), 0, 500);

        $created = Db::tx(static function () use ($service, $shownTarget, $check, $visitor, $auto, $day, $now, $ref, $gatewayOrderId, $gwRow): array {
            $userId = null;
            if ($visitor['visitor_id']) {
                Db::exec(
                    'INSERT INTO users (visitor_id, orders_count, first_order_at, last_order_at, created_at, updated_at) VALUES (?, 1, ?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE orders_count = orders_count + 1, last_order_at = VALUES(last_order_at), updated_at = VALUES(updated_at)',
                    [$visitor['visitor_id'], Clock::sql($now), Clock::sql($now), Clock::sql($now), Clock::sql($now)]
                );
                $userId = (int) Db::val('SELECT id FROM users WHERE visitor_id = ?', [$visitor['visitor_id']]);
            }

            $orderId = 0;
            $publicId = '';
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $publicId = self::newPublicId();
                $n = Db::exec(
                    'INSERT IGNORE INTO orders (public_id, access_token, user_id, visitor_id, visitor_session_id, service_id, platform_id, platform_slug, service_code, '
                    . 'service_title, service_type, mode, quantity, amount_paise, price_version_id, experiment_id, price_cohort, target, target_url, status, payment_status, smm_provider_id, smm_service_id, '
                    . "estimated_cost_paise, provider_cost_paise, created_day, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_PAYMENT', 'pending', ?, ?, ?, 0, ?, ?, ?)",
                    [
                        $publicId, Crypto::token(16), $userId, $visitor['visitor_id'], $visitor['session_id'], $service['id'], $service['platform_id'],
                        $service['platform_slug'], $service['code'], $service['title'], $service['type'], $service['mode'], $service['quantity'], $service['price_paise'],
                        $service['price_version_id'] ?? null, $service['experiment_id'] ?? null, $service['price_cohort'] ?? null,
                        $shownTarget, $check['url'],
                        $auto ? $service['smm_provider_id'] : null, $auto ? $service['smm_service_id'] : null,
                        $auto ? (int) $service['smm_cost_paise'] : 0,
                        $day, Clock::sql($now), Clock::sql($now),
                    ]
                );
                if ($n === 1) {
                    $orderId = Db::lastId();
                    break;
                }
            }
            if ($orderId === 0) {
                throw new \RuntimeException('Could not allocate a unique order id.');
            }

            // Capture the id IMMEDIATELY: any later INSERT (e.g. the history row) would change lastInsertId().
            $paymentId = Db::insert('payments', [
                'ref' => $ref, 'order_id' => $orderId, 'gateway_id' => $gwRow['id'], 'provider' => $gwRow['provider'],
                'gateway_order_id' => $gatewayOrderId, 'amount_paise' => $service['price_paise'], 'currency' => 'INR',
                'status' => Status::PAY_CREATING, 'expires_at' => Clock::sql($now + self::PAYMENT_TTL),
                'created_day' => $day, 'created_at' => Clock::sql($now), 'updated_at' => Clock::sql($now),
            ]);
            self::history($orderId, null, Status::PENDING_PAYMENT, 'customer', null, 'Order created — waiting for payment · ' . $check['url'], ['target_url' => $check['url']]);
            return ['order_id' => $orderId, 'public_id' => $publicId, 'payment_id' => $paymentId];
        });

        Tracking::onOrderCreated($visitor['visitor_id'], $visitor['session_id']);
        Analytics::markDirty($day);

        // ── ask the gateway (outside any DB transaction: it is a network call) ──
        $gateway = Gateways::adapter($gwRow);
        $result = $gateway->createPayment(new CreatePaymentRequest(
            gatewayOrderId: $gatewayOrderId,
            amountPaise: (int) $service['price_paise'],
            redirectUrl: absolute_url('/pay/' . $ref . '?returned=1'),
            remark1: $created['public_id'],
            remark2: mb_substr((string) $service['title'], 0, 60),
        ));

        // A QR may only carry a UPI intent for exactly this order and amount (INR). The gateway's hosted checkout URL is stored as
        // payment_url and is where the customer is sent; it is never turned into a QR.
        $upi = null;
        if ($result->ok && $result->upiIntent !== null) {
            $upi = Upi::validateFor($result->upiIntent, (int) $service['price_paise']);
            if ($upi === null) {
                Log::warning('The gateway returned a UPI intent that does not match the order (format, currency or amount); it is not used', ['order' => $created['public_id']]);
            }
        }
        $hosted = $result->paymentUrl !== null && preg_match('#^https?://#i', $result->paymentUrl) === 1 ? $result->paymentUrl : null;

        if (!$result->ok || ($hosted === null && $upi === null)) {
            $reason = $result->message !== '' ? $result->message
                : ($result->ok ? 'The gateway returned neither a payment page nor a valid UPI payment request for this amount.' : 'Payment could not be created.');
            $reason = mb_substr($reason, 0, 255);
            Db::tx(static function () use ($created, $reason): void {
                Db::update('payments', ['status' => Status::PAY_FAILED, 'failure_reason' => $reason, 'updated_at' => Clock::sql()], 'id = ?', [$created['payment_id']]);
                Db::update('orders', ['payment_status' => 'failed', 'last_error' => $reason], 'id = ?', [$created['order_id']]);
                self::transition((int) $created['order_id'], Status::FAILED, ['actor' => 'system', 'note' => 'Payment gateway refused or was unreachable: ' . $reason, 'soft' => true]);
            });
            throw new HttpException(503, 'Payment is temporarily unavailable. Please try again in a few minutes.', 'payments_unavailable');
        }

        Log::info('Payment session created', [
            'order' => $created['public_id'], 'qr' => $upi !== null ? 'upi_request' : 'none (hosted checkout)', 'payee' => $upi['payee'] ?? null,
            'amount_paise' => (int) $service['price_paise'],
        ]);
        Db::update('payments', [
            'status' => Status::PAY_PENDING, 'payment_url' => $hosted, 'qr_payload' => $upi['uri'] ?? null,
            'create_response' => json_encode($result->raw, JSON_UNESCAPED_SLASHES), 'updated_at' => Clock::sql(),
        ], "id = ? AND status = 'creating'", [$created['payment_id']]);

        return ['ref' => $ref, 'order_id' => $created['public_id'], 'reused' => false];
    }

    /**
     * Where the customer goes right after checkout: our payment page when it can show the payment QR; otherwise straight to the gateway's own
     * checkout (the documented flow: "redirect your customer to this URL") — an intermediate page with nothing to scan would only be in the way.
     *
     * @param array<string,mixed> $payment
     */
    public static function continueUrl(array $payment): string
    {
        $hasQr = Upi::validateFor($payment['qr_payload'] ?? null, (int) $payment['amount_paise']) !== null;
        $hosted = (string) ($payment['payment_url'] ?? '');
        return !$hasQr && preg_match('#^https?://#i', $hosted) === 1 ? $hosted : url('pay/' . $payment['ref']);
    }

    // ───────────────────────── state machine ────────────────────────────────

    /**
     * Move an order to a new status (validated against Status::canTransition)
     * and append to its history, atomically.
     *
     * @param array{actor?:string,actor_id?:int|null,actor_label?:string,note?:string,meta?:array,set?:array<string,mixed>,expect?:list<string>,soft?:bool} $opts
     * @return bool true when the status changed
     */
    public static function transition(int $orderId, string $to, array $opts = []): bool
    {
        $soft = (bool) ($opts['soft'] ?? false);
        $applied = Db::tx(static function () use ($orderId, $to, $opts, $soft): ?array {
            $row = Db::one('SELECT id, status, paid_day, created_day FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($row === null) {
                throw new HttpException(404, 'Order not found.');
            }
            $from = (string) $row['status'];
            if ($from === $to) {
                return null;
            }
            if (isset($opts['expect']) && !in_array($from, $opts['expect'], true)) {
                if ($soft) {
                    return null;
                }
                throw new HttpException(409, 'The order changed in the meantime. Reload and try again.', 'conflict');
            }
            if (!Status::canTransition($from, $to)) {
                if ($soft) {
                    return null;
                }
                throw new HttpException(409, 'An order that is “' . Status::orderLabel($from) . '” cannot be moved to “' . Status::orderLabel($to) . '”.', 'invalid_transition');
            }
            $set = ['status' => $to, 'updated_at' => Clock::sql()] + ($opts['set'] ?? []);
            if (in_array($to, Status::FULFILLED, true) && !array_key_exists('completed_at', $set)) {
                $set['completed_at'] = Clock::sql();
            }
            Db::update('orders', $set, 'id = ?', [$orderId]);
            self::history($orderId, $from, $to, (string) ($opts['actor'] ?? 'system'), $opts['actor_id'] ?? null, $opts['note'] ?? null, $opts['meta'] ?? null, $opts['actor_label'] ?? null);
            return ['paid_day' => $row['paid_day'], 'created_day' => $row['created_day']];
        });

        if ($applied === null) {
            return false;
        }
        foreach (array_filter([$applied['paid_day'], $applied['created_day']]) as $d) {
            Analytics::markDirty((string) $d);
        }
        return true;
    }

    /** @param array<string,mixed>|null $meta */
    public static function history(int $orderId, ?string $from, string $to, string $actor, ?int $actorId, ?string $note, ?array $meta = null, ?string $label = null): void
    {
        Db::insert('order_status_history', [
            'order_id' => $orderId, 'from_status' => $from, 'to_status' => $to, 'actor_type' => $actor, 'actor_id' => $actorId,
            'actor_label' => $label !== null ? mb_substr($label, 0, 120) : null,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => Clock::sql(),
        ]);
    }

    // ───────────────────────── lookups ──────────────────────────────────────

    public static function byId(int $id): ?array
    {
        return Db::one('SELECT * FROM orders WHERE id = ?', [$id]);
    }

    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return Db::one('SELECT * FROM orders WHERE access_token = ?', [$token]);
    }

    public static function paymentByRef(string $ref): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $ref)) {
            return null;
        }
        return Db::one('SELECT * FROM payments WHERE ref = ?', [$ref]);
    }

    /**
     * What a CUSTOMER may see about their own order (looked up by its
     * unguessable token). No provider data, no internal notes, no errors.
     *
     * @return array<string,mixed>
     */
    public static function customerView(array $o): array
    {
        $history = Db::all('SELECT to_status, created_at FROM order_status_history WHERE order_id = ? ORDER BY id', [$o['id']]);
        $steps = [];
        foreach ($history as $h) {
            $label = match ($h['to_status']) {
                Status::PENDING_PAYMENT => 'Order created',
                Status::PAID, Status::MANUAL_PENDING => 'Payment received',
                Status::SUBMITTED, Status::IN_PROGRESS => 'Delivery started',
                Status::MANUAL_APPROVED => 'Approved by our team',
                Status::COMPLETED => 'Completed',
                Status::PARTIAL => 'Partially completed',
                Status::CANCELLED => 'Cancelled',
                Status::REFUNDED => 'Refunded',
                Status::EXPIRED => 'Payment session expired',
                default => null,
            };
            if ($label !== null && (!$steps || end($steps)['label'] !== $label)) {
                $steps[] = ['label' => $label, 'at' => Clock::iso($h['created_at'])];
            }
        }
        return [
            'order_id'    => $o['public_id'],
            'service'     => $o['service_title'],
            'platform'    => $o['platform_slug'],
            'quantity'    => (int) $o['quantity'],
            'amount'      => Money::inr((int) $o['amount_paise']),
            'target'      => $o['target'],
            'status'      => $o['status'],
            'status_label' => Status::customerLabel((string) $o['status'], (string) $o['mode']),
            'paid'        => $o['paid_at'] !== null,
            'done'        => in_array($o['status'], Status::FULFILLED, true),
            'created_at'  => Clock::iso($o['created_at']),
            'steps'       => $steps,
        ];
    }
}
