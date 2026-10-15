<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Status;

/**
 * Order recovery: which paid orders are not where they should be, why, and what a person may safely do about it.
 *
 * Paid orders reach the SMM provider through Fulfilment, which already protects against double orders (an order is CLAIMED under a row lock
 * before the provider is called, an outcome nobody can know is never retried by itself, a process that died mid-submission is handed to a
 * human). This service adds the view and the controls around that:
 *
 *   queue / summary   the orders that need attention, derived live from the orders table — nothing here is a stored copy that could go stale:
 *                       waiting   paid AUTO orders not yet sent                      retry    a retry is scheduled (provider unreachable, nothing sent)
 *                       sending   claimed for sending, no answer recorded for > 5 min  unknown  the provider may or may not have the order
 *                       review    a person was asked to look / it was escalated      failed   refused by the provider or retries exhausted
 *                       stale     at the provider, status not refreshed > 30 min      errors   status lookups keep failing (≥ 3 in 2 h)
 *   actions           retry, link a provider order, move to the manual queue, sync, mark for review, escalate, resolve — each one audited,
 *                     each one checked against the order's state, and the one that could create a SECOND provider order (retrying an order whose
 *                     outcome is unknown) refused unless the admin states, with a note, that the provider's panel shows no order for this link.
 *
 * What cannot be automated, honestly: the SMM API v2 has no idempotency key and no lookup by link. An ambiguous submission can therefore only be
 * reconciled by a person looking at the provider's panel — by linking the provider order id that is there (verified with the provider's own
 * `status` call), or by confirming there is none and resending. Never by guessing.
 */
final class Recovery
{
    /** key => [label, what it means]. */
    public const CATEGORIES = [
        'unknown' => ['Provider outcome unknown', 'The provider may or may not have this order (a timeout, a dropped connection or an unclear answer). It is never resent by itself: check the provider panel for this link, then link the provider order you find, resend after confirming there is none, or move the order to the manual queue.'],
        'review'  => ['Needs review', 'A person was asked to look at this order — a misconfiguration, or it was marked for review or escalated. Automatic sending is paused until someone acts.'],
        'failed'  => ['Failed', 'The provider refused the order, or it could not be sent after every attempt. Fix the cause and resend, move it to the manual queue, or cancel it and record a refund.'],
        'sending' => ['Sending is stuck', 'The order was claimed for sending but no answer was recorded for over 5 minutes. After 10 minutes the watchdog hands it to a person; it is never resent by itself.'],
        'errors'  => ['Repeated provider errors', 'Asking the provider for this order\'s status failed 3 or more times in the last 2 hours.'],
        'stale'   => ['Provider not followed', 'The order is at the provider but its status has not been refreshed for over 30 minutes.'],
        'waiting' => ['Waiting to be sent', 'A paid AUTO order that has not been sent to the provider yet. This normally takes seconds.'],
        'retry'   => ['Retry scheduled', 'The provider could not be reached (nothing was sent), so the shop tries again by itself, with growing delays, up to the maximum number of attempts.'],
    ];

    public const SENDING_STUCK_AFTER = 300;
    public const STALE_AFTER = 1800;
    public const ERROR_WINDOW = 7200;
    public const ERROR_COUNT = 3;
    private const PAGE = 25;

    /** Actions this service performs; all need the permission `orders.recover`. */
    public const ACTIONS = ['retry_submit', 'link_provider_order', 'move_to_manual', 'sync', 'mark_review', 'escalate', 'resolve_review'];

    // ── what is in the queue ─────────────────────────────────────────────────

    /** @return array<string,array{0:string,1:list<mixed>}> category => [SQL condition on orders `o`, its parameters] */
    private static function conditions(int $now): array
    {
        $at = static fn(int $secondsAgo): string => Clock::sql($now - $secondsAgo);
        $live = "o.status IN ('SUBMITTED', 'IN_PROGRESS') AND o.provider_order_id IS NOT NULL";
        return [
            'unknown' => ["o.status = 'FAILED' AND o.paid_at IS NOT NULL AND o.failure_kind = 'ambiguous' AND COALESCE(o.recovery_status, '') <> 'resolved'", []],
            'review'  => ["o.paid_at IS NOT NULL AND o.status IN ('PAID', 'PROCESSING', 'FAILED', 'SUBMITTED', 'IN_PROGRESS') AND o.recovery_status IN ('needs_review', 'escalated')", []],
            'failed'  => ["o.status = 'FAILED' AND o.paid_at IS NOT NULL AND COALESCE(o.failure_kind, '') <> 'ambiguous' AND COALESCE(o.recovery_status, '') <> 'resolved'", []],
            'sending' => ["o.status = 'PROCESSING' AND o.paid_at IS NOT NULL AND o.updated_at < ?", [$at(self::SENDING_STUCK_AFTER)]],
            'errors'  => ["{$live} AND (SELECT COUNT(*) FROM smm_transactions x WHERE x.order_id = o.id AND x.action = 'status' AND x.ok = 0 AND x.created_at >= ?) >= " . self::ERROR_COUNT, [$at(self::ERROR_WINDOW)]],
            'stale'   => ["{$live} AND COALESCE(o.last_sync_at, o.submitted_at) < ?", [$at(self::STALE_AFTER)]],
            'waiting' => ["o.mode = 'AUTO' AND o.status = 'PAID' AND o.paid_at IS NOT NULL AND o.provider_order_id IS NULL AND (o.next_attempt_at IS NULL OR o.next_attempt_at <= ?)", [Clock::sql($now)]],
            'retry'   => ["o.mode = 'AUTO' AND o.status = 'PAID' AND o.paid_at IS NOT NULL AND o.provider_order_id IS NULL AND o.next_attempt_at > ?", [Clock::sql($now)]],
        ];
    }

    /**
     * How many orders are in each category (an order can be in more than one), how many need a person, and the last run of the recovery job.
     *
     * @return array{counts:array<string,int>,needs_person:int,in_queue:int,job:?array<string,mixed>,policy:array<string,mixed>}
     */
    public static function summary(): array
    {
        $cond = self::conditions(Clock::now());
        $parts = [];
        $args = [];
        foreach ($cond as $key => [$sql, $p]) {
            $parts[] = "COALESCE(SUM(CASE WHEN ({$sql}) THEN 1 ELSE 0 END), 0) AS c_{$key}";
            array_push($args, ...$p);
        }
        $person = ['unknown', 'review', 'failed'];
        $parts[] = 'COALESCE(SUM(CASE WHEN ' . implode(' OR ', array_map(static fn(string $k): string => '(' . $cond[$k][0] . ')', $person)) . ' THEN 1 ELSE 0 END), 0) AS needs_person';
        foreach ($person as $k) {
            array_push($args, ...$cond[$k][1]);
        }
        $parts[] = 'COALESCE(SUM(CASE WHEN ' . implode(' OR ', array_map(static fn(array $c): string => '(' . $c[0] . ')', $cond)) . ' THEN 1 ELSE 0 END), 0) AS in_queue';
        foreach ($cond as [, $p]) {
            array_push($args, ...$p);
        }
        $row = Db::one("SELECT " . implode(', ', $parts) . " FROM orders o WHERE o.paid_at IS NOT NULL AND o.status IN ('PAID', 'PROCESSING', 'SUBMITTED', 'IN_PROGRESS', 'FAILED')", $args) ?? [];
        $counts = [];
        foreach (array_keys(self::CATEGORIES) as $k) {
            $counts[$k] = (int) ($row['c_' . $k] ?? 0);
        }
        $job = null;
        try {
            foreach (Jobs::overview() as $j) {
                if ($j['job'] === 'recovery') {
                    $job = $j;
                }
            }
        } catch (\Throwable) {
            $job = null;       // job history arrives with a database update that may not be applied yet
        }
        return ['counts' => $counts, 'needs_person' => (int) ($row['needs_person'] ?? 0), 'in_queue' => (int) ($row['in_queue'] ?? 0), 'job' => $job, 'policy' => self::policy()];
    }

    /** @return array<string,mixed> */
    public static function policy(): array
    {
        return [
            'max_attempts' => Fulfilment::maxAttempts(), 'default_max_attempts' => Fulfilment::MAX_AUTO_ATTEMPTS, 'bounds' => [1, 10], 'backoff_seconds' => Fulfilment::BACKOFF,
            'retried_automatically' => 'only when the provider could not be reached and nothing was sent',
            'never_retried_automatically' => 'an outcome nobody can know (timeout after sending, dropped connection, unclear answer) and an order whose sending was interrupted',
            'idempotency_key' => 'not supported by the SMM API v2 — duplicate protection is the claim lock plus never resending an unknown outcome',
        ];
    }

    /**
     * The queue, most urgent first (a person is needed → stuck → waiting), oldest first within a group.
     *
     * @param array{category?:string,q?:string,page?:int} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per:int}
     */
    public static function queue(array $f = []): array
    {
        $cond = self::conditions(Clock::now());
        $category = (string) ($f['category'] ?? '');
        if ($category !== '' && !isset($cond[$category])) {
            throw new HttpException(422, 'Unknown category.', 'invalid');
        }
        $where = [];
        $whereArgs = [];
        if ($category !== '') {
            $where[] = '(' . $cond[$category][0] . ')';
            array_push($whereArgs, ...$cond[$category][1]);
        } else {
            $where[] = '(' . implode(' OR ', array_map(static fn(array $c): string => '(' . $c[0] . ')', $cond)) . ')';
            foreach ($cond as [, $p]) {
                array_push($whereArgs, ...$p);
            }
        }
        $search = trim((string) ($f['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(o.public_id LIKE ? OR o.provider_order_id = ? OR o.target_url LIKE ? OR o.target LIKE ?)';
            $like = '%' . addcslashes(mb_substr($search, 0, 100), '%_\\') . '%';
            array_push($whereArgs, $like, $search, $like, $like);
        }
        $w = "o.paid_at IS NOT NULL AND o.status IN ('PAID', 'PROCESSING', 'SUBMITTED', 'IN_PROGRESS', 'FAILED') AND " . implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM orders o WHERE {$w}", $whereArgs);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $order = 'c_unknown DESC, c_review DESC, c_failed DESC, c_sending DESC, c_errors DESC, c_stale DESC, c_waiting DESC, c_retry DESC, o.paid_at ASC, o.id ASC';
        return ['rows' => self::rows($w, $whereArgs, $order . ' LIMIT ' . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE)), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => self::PAGE];
    }

    /**
     * One order as the recovery screen and the order drawer show it (`categories` is empty when it is not in the queue); null when it does not exist.
     *
     * @return array<string,mixed>|null
     */
    public static function one(int $orderId): ?array
    {
        return self::rows('o.id = ?', [$orderId], 'o.id')[0] ?? null;
    }

    /**
     * @param list<mixed> $whereArgs
     * @return list<array<string,mixed>>
     */
    private static function rows(string $where, array $whereArgs, string $orderBy): array
    {
        $cond = self::conditions(Clock::now());
        $select = [];
        $args = [];
        foreach ($cond as $key => [$sql, $p]) {
            $select[] = "(CASE WHEN ({$sql}) THEN 1 ELSE 0 END) AS c_{$key}";
            array_push($args, ...$p);
        }
        $rows = Db::all(
            'SELECT o.*, pr.name AS provider_name, ad.username AS escalated_by_name, ' . implode(', ', $select) . ' FROM orders o '
            . 'LEFT JOIN smm_providers pr ON pr.id = o.smm_provider_id LEFT JOIN admins ad ON ad.id = o.escalated_by '
            . "WHERE {$where} ORDER BY {$orderBy}",
            array_merge($args, $whereArgs)
        );
        return array_map([self::class, 'shape'], $rows);
    }

    /** @param array<string,mixed> $o @return array<string,mixed> */
    private static function shape(array $o): array
    {
        $cats = [];
        foreach (array_keys(self::CATEGORIES) as $k) {
            if ((int) ($o['c_' . $k] ?? 0) === 1) {
                $cats[] = $k;
            }
        }
        $statusText = (string) $o['status'];
        return [
            'id' => (int) $o['id'], 'public_id' => $o['public_id'], 'platform' => $o['platform_slug'], 'service' => $o['service_title'], 'quantity' => (int) $o['quantity'],
            'target' => $o['target_url'] ?: $o['target'], 'mode' => $o['mode'], 'status' => $statusText, 'status_label' => Status::orderLabel($statusText), 'state' => Status::specOrder($o),
            'failure_kind' => $o['failure_kind'], 'recovery_status' => $o['recovery_status'], 'categories' => $cats, 'primary' => $cats[0] ?? null,
            'category_labels' => array_combine($cats, array_map(static fn(string $k): string => self::CATEGORIES[$k][0], $cats)) ?: [],
            'explanation' => $cats ? self::CATEGORIES[$cats[0]][1] : null,
            'attempts' => (int) $o['provider_attempts'], 'max_attempts' => Fulfilment::maxAttempts(),
            'paid_at' => Clock::iso($o['paid_at']), 'last_attempt_at' => Clock::iso($o['last_attempt_at']), 'next_attempt_at' => Clock::iso($o['next_attempt_at']),
            'error' => $o['last_error'] !== null ? Jobs::clean((string) $o['last_error']) : null,
            'provider' => ['name' => $o['provider_name'], 'order_id' => $o['provider_order_id'], 'status' => $o['provider_status'], 'last_sync_at' => Clock::iso($o['last_sync_at'] ?? null)],
            'escalated_at' => Clock::iso($o['escalated_at']), 'escalated_by' => $o['escalated_by_name'],
            'actions' => self::actionsFor($o),
        ];
    }

    // ── what may be done ─────────────────────────────────────────────────────

    /** Providers' own words for an order they will not deliver. */
    private static function providerGaveUp(array $o): bool
    {
        return in_array(strtolower((string) ($o['provider_status'] ?? '')), ['fail', 'failed', 'canceled', 'cancelled', 'error'], true);
    }

    /**
     * Which recovery actions make sense for this order right now (the permission is checked separately).
     *
     * @param array<string,mixed> $o an orders row
     * @return list<string>
     */
    public static function actionsFor(array $o): array
    {
        if (($o['mode'] ?? '') !== Status::AUTO || ($o['paid_at'] ?? null) === null) {
            return [];
        }
        $s = (string) $o['status'];
        $mark = (string) ($o['recovery_status'] ?? '');
        $hasLiveProviderOrder = $o['provider_order_id'] !== null && !self::providerGaveUp($o);
        $a = [];
        if ($s === Status::PAID && $o['provider_order_id'] === null) {
            array_push($a, 'retry_submit', 'move_to_manual');
        } elseif ($s === Status::FAILED) {
            if (!$hasLiveProviderOrder) {
                $a[] = 'retry_submit';
                if ($o['provider_order_id'] === null) {
                    $a[] = 'link_provider_order';
                }
                $a[] = 'move_to_manual';
            } else {
                $a[] = 'sync';
            }
        } elseif (in_array($s, [Status::SUBMITTED, Status::IN_PROGRESS], true) && $o['provider_order_id'] !== null) {
            $a[] = 'sync';
        }
        if (in_array($s, [Status::PAID, Status::PROCESSING, Status::FAILED, Status::SUBMITTED, Status::IN_PROGRESS], true)) {
            if ($mark !== 'needs_review' && $mark !== 'escalated') {
                $a[] = 'mark_review';
            }
            if ($mark !== 'escalated') {
                $a[] = 'escalate';
            }
            if (in_array($mark, ['needs_review', 'escalated'], true) || ($s === Status::FAILED && $mark !== 'resolved')) {
                $a[] = 'resolve_review';
            }
        }
        return $a;
    }

    /**
     * Perform one recovery action. Every action is audited and leaves a line in the order's timeline.
     *
     * @param array<string,mixed> $in {action, note?, verified_absent?, provider_order_id?}
     * @param array{id:int,username:string,email:string} $admin
     * @return array{message:string,result?:string}
     */
    public static function act(int $orderId, array $in, ?Request $req, array $admin): array
    {
        $o = Orders::byId($orderId) ?? throw new HttpException(404, 'Order not found.');
        $action = (string) ($in['action'] ?? '');
        $note = trim(mb_substr((string) ($in['note'] ?? ''), 0, 400));
        if (!in_array($action, self::actionsFor($o), true)) {
            throw new HttpException(409, 'That action is not available for this order right now.', 'not_allowed');
        }
        return match ($action) {
            'retry_submit'        => self::retry($o, $admin, $req, ($in['verified_absent'] ?? false) === true, $note),
            'link_provider_order' => self::link($o, (string) ($in['provider_order_id'] ?? ''), $admin, $req, $note),
            'move_to_manual'      => self::toManual($o, $admin, $req),
            'sync'                => self::sync($o, $admin, $req),
            default               => self::mark($o, $action, $note, $admin, $req),
        };
    }

    /**
     * Send the order to the provider again. An order whose last outcome is UNKNOWN may already exist at the provider, so resending it
     * needs the admin's explicit statement — with a note — that the provider's panel shows no order for this link.
     *
     * @return array{message:string,result:string}
     */
    private static function retry(array $o, array $admin, ?Request $req, bool $verifiedAbsent, string $note): array
    {
        $unknown = (string) ($o['failure_kind'] ?? '') === 'ambiguous';
        if ($unknown) {
            $fields = [];
            if (!$verifiedAbsent) {
                $fields['verified_absent'] = 'Confirm that you checked the provider panel and that no order exists for this link.';
            }
            if (mb_strlen($note) < 5) {
                $fields['note'] = 'Say what you checked (at least 5 characters).';
            }
            if ($fields) {
                throw new HttpException(422, 'This order may already exist at the provider. Check its panel for this link first.', 'verification_required', ['fields' => $fields]);
            }
            Orders::history((int) $o['id'], (string) $o['status'], (string) $o['status'], 'admin', $admin['id'], 'Checked at the provider panel that no order exists for this link — resending. ' . $note, null, $admin['username']);
        }
        Audit::log('order.recovery.retry', 'order', (int) $o['id'], ['status' => $o['status'], 'outcome_was_unknown' => $unknown, 'note' => $note], $req, $admin);
        $r = Fulfilment::submit((int) $o['id'], $admin, true);
        return ['message' => $r['status'] === 'submitted' ? 'Submitted to the provider.' : ($r['message'] ?? 'The retry did not succeed — see the order timeline.'), 'result' => (string) $r['status']];
    }

    /**
     * Adopt the provider order id a person found in the provider's panel. The provider is asked about that id with its own `status` call
     * and must know it; an id already linked to another order is refused. The order then continues as if the provider had answered normally.
     *
     * @return array{message:string,result:string}
     */
    private static function link(array $o, string $providerOrderId, array $admin, ?Request $req, string $note): array
    {
        $providerOrderId = trim($providerOrderId);
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $providerOrderId)) {
            throw new HttpException(422, 'Enter the order id shown in the provider panel (letters, numbers, - and _).', 'invalid', ['fields' => ['provider_order_id' => 'Enter the provider\'s order id.']]);
        }
        if ($o['provider_order_id'] !== null) {
            throw new HttpException(409, 'This order already has a provider order id.', 'conflict');
        }
        $provider = $o['smm_provider_id'] ? SmmProviders::row((int) $o['smm_provider_id']) : SmmProviders::defaultRow();
        if ($provider === null || !(int) $provider['is_active']) {
            throw new HttpException(409, 'There is no active SMM provider to ask. Connect one under Admin → SMM API first.', 'no_provider');
        }
        // One provider order must never end up on two of our orders, even if two admins link it in the same instant: the check and the change
        // happen under a named lock for that provider order id.
        $lock = 'link:' . $provider['id'] . ':' . $providerOrderId;
        if (!Db::tryLock($lock, 5)) {
            throw new HttpException(409, 'Someone is linking that provider order right now. Try again in a moment.', 'busy');
        }
        $id = (int) $o['id'];
        try {
            if (Db::val('SELECT id FROM orders WHERE smm_provider_id = ? AND provider_order_id = ? AND id <> ? LIMIT 1', [$provider['id'], $providerOrderId, $id]) !== null) {
                throw new HttpException(409, 'That provider order is already linked to another order of yours.', 'conflict', ['fields' => ['provider_order_id' => 'Already linked to another order.']]);
            }
            $res = SmmProviders::client($provider)->status($providerOrderId);
            Fulfilment::logTransaction($o, $provider, 'status', ['order' => $providerOrderId, 'purpose' => 'link'], $res, $providerOrderId);
            if (!$res->ok() || !is_array($res->data) || !isset($res->data['status']) || !is_scalar($res->data['status'])) {
                throw new HttpException(409, 'The provider did not confirm an order with that id' . ($res->error !== '' ? ' (' . Jobs::clean($res->error) . ')' : '') . '. Nothing was changed.', 'not_confirmed', ['fields' => ['provider_order_id' => 'The provider does not know this id.']]);
            }
            $providerStatus = mb_substr((string) $res->data['status'], 0, 32);
            Db::tx(static function () use ($id, $o, $provider, $providerOrderId, $providerStatus, $admin, $note): void {
                Orders::transition($id, Status::PROCESSING, [
                    'actor' => 'admin', 'actor_id' => $admin['id'], 'actor_label' => $admin['username'], 'note' => 'Linking a provider order found in the provider panel', 'expect' => [Status::PAID, Status::FAILED],
                ]);
                Orders::transition($id, Status::SUBMITTED, [
                    'actor' => 'admin', 'actor_id' => $admin['id'], 'actor_label' => $admin['username'],
                    'note' => 'Provider order ' . $providerOrderId . ' linked after checking it at the provider (status: ' . $providerStatus . ').' . ($note !== '' ? ' ' . $note : ''),
                    'set' => [
                        'provider_order_id' => $providerOrderId, 'provider_status' => $providerStatus, 'smm_provider_id' => $provider['id'], 'provider_cost_paise' => (int) $o['estimated_cost_paise'],
                        'provider_cost_final' => 0, 'submitted_at' => Clock::sql(), 'last_attempt_at' => Clock::sql(), 'last_error' => null, 'failure_kind' => null, 'next_attempt_at' => null, 'recovery_status' => 'resolved',
                    ],
                ]);
            });
        } finally {
            Db::unlock($lock);
        }
        Audit::log('order.recovery.linked', 'order', $id, ['provider_order_id' => $providerOrderId, 'provider_status' => $providerStatus, 'note' => $note], $req, $admin);
        try {
            Fulfilment::sync($id);
        } catch (\Throwable) {
            // the link is made; the regular sync picks the order up
        }
        return ['message' => 'Provider order ' . $providerOrderId . ' linked. The order now follows the provider\'s status.', 'result' => 'linked'];
    }

    /** @return array{message:string,result:string} */
    private static function toManual(array $o, array $admin, ?Request $req): array
    {
        Fulfilment::moveToManual((int) $o['id'], $admin, $req);
        Db::update('orders', ['recovery_status' => 'resolved'], "id = ? AND recovery_status IN ('auto_retry', 'needs_review', 'escalated')", [$o['id']]);
        return ['message' => 'Moved to the manual queue.', 'result' => 'moved'];
    }

    /** @return array{message:string,result:string} */
    private static function sync(array $o, array $admin, ?Request $req): array
    {
        $r = Fulfilment::sync((int) $o['id']);
        Audit::log('order.recovery.sync', 'order', (int) $o['id'], ['ok' => $r['ok']], $req, $admin);
        if (!$r['ok']) {
            throw new HttpException(502, 'Could not sync: ' . $r['message'], 'provider_error');
        }
        return ['message' => $r['message'], 'result' => 'synced'];
    }

    /**
     * mark_review → needs_review, escalate → escalated (who and when are kept), resolve_review → resolved. Escalating or marking for review
     * pauses automatic sending of a waiting order (Fulfilment::submitDue skips it) until a person acts; resolving lets it carry on.
     *
     * @return array{message:string,result:string}
     */
    private static function mark(array $o, string $action, string $note, array $admin, ?Request $req): array
    {
        if (in_array($action, ['escalate', 'resolve_review'], true) && mb_strlen($note) < 3) {
            throw new HttpException(422, 'Add a short note.', 'invalid', ['fields' => ['note' => 'Required (at least 3 characters).']]);
        }
        [$status, $verb, $audit] = match ($action) {
            'mark_review'    => ['needs_review', 'Marked for review', 'order.recovery.review'],
            'escalate'       => ['escalated', 'Escalated', 'order.recovery.escalated'],
            default          => ['resolved', 'Resolved', 'order.recovery.resolved'],
        };
        $set = ['recovery_status' => $status, 'updated_at' => Clock::sql()];
        if ($action === 'escalate') {
            $set['escalated_at'] = Clock::sql();
            $set['escalated_by'] = $admin['id'];
        }
        Db::update('orders', $set, 'id = ?', [$o['id']]);
        Orders::history((int) $o['id'], (string) $o['status'], (string) $o['status'], 'admin', $admin['id'], $verb . ($note !== '' ? ': ' . $note : ''), null, $admin['username']);
        Audit::log($audit, 'order', (int) $o['id'], ['status' => $o['status'], 'note' => $note], $req, $admin);
        return ['message' => $verb . '.', 'result' => $status];
    }

    // ── policy ───────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $in @param array{id:int,username:string,email:string} $admin @return array<string,mixed> */
    public static function saveSettings(array $in, array $admin, ?Request $req = null): array
    {
        if (array_key_exists('max_attempts', $in)) {
            $v = $in['max_attempts'];
            if ((!is_int($v) && !(is_string($v) && preg_match('/^\d{1,3}$/D', $v))) || (int) $v < 1 || (int) $v > 10) {
                throw new HttpException(422, 'Enter a number from 1 to 10.', 'invalid', ['fields' => ['max_attempts' => 'Enter a number from 1 to 10.']]);
            }
            Settings::set('recovery.max_attempts', (int) $v, $admin['id']);
            Audit::log('recovery.settings_changed', 'settings', 'recovery', ['max_attempts' => (int) $v], $req, $admin);
        }
        return self::policy();
    }

    // ── cron ─────────────────────────────────────────────────────────────────

    /**
     * Part of the `recovery` job: tidy the recovery marks and report the size of the queue. A dry run changes nothing.
     *
     * @return array<string,mixed>
     */
    public static function scan(JobContext $c): array
    {
        $write = !$c->dryRun;
        $out = ['marked_for_review' => 0, 'marks_cleared' => 0];
        // an outcome nobody can know, or a setup problem, always carries the review mark (also for orders that failed before this release)
        $where = "status = 'FAILED' AND paid_at IS NOT NULL AND failure_kind IN ('ambiguous', 'misconfigured') AND recovery_status IS NULL";
        $out['marked_for_review'] = $write
            ? Db::exec("UPDATE orders SET recovery_status = 'needs_review' WHERE {$where}")
            : (int) Db::val("SELECT COUNT(*) FROM orders WHERE {$where}");
        // an order that has left the recovery states (delivered, cancelled, refunded, moved to the manual queue) no longer needs the mark
        $gone = "recovery_status IN ('auto_retry', 'needs_review', 'escalated') AND status IN ('COMPLETED', 'PARTIAL', 'CANCELLED', 'REFUNDED', 'EXPIRED', 'MANUAL_PENDING', 'MANUAL_APPROVED')";
        $out['marks_cleared'] = $write
            ? Db::exec("UPDATE orders SET recovery_status = 'resolved' WHERE {$gone}")
            : (int) Db::val("SELECT COUNT(*) FROM orders WHERE {$gone}");
        $s = self::summary();
        return $out + ['queue' => $s['counts'], 'needs_person' => $s['needs_person']];
    }
}
