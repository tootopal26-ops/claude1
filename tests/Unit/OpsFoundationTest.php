<?php
declare(strict_types=1);

use App\Services\JobContext;
use App\Services\Jobs;
use App\Support\Permissions;
use App\Support\Status;

/**
 * The foundation of the intelligence work: who may do what, the requested state vocabulary, and the sanitising of job / alert text.
 * (Pure functions — no database.)
 */

T::test('permissions: owner can do everything, an unknown role and an unknown permission nothing', function () {
    foreach (array_keys(Permissions::ALL) as $perm) {
        T::true(Permissions::can('owner', $perm), "owner: $perm");
        T::false(Permissions::can('nobody', $perm), "unknown role: $perm");
    }
    T::false(Permissions::can('owner', 'made.up'), 'a permission that does not exist is never granted — not even to the owner');
    T::same(array_keys(Permissions::ALL), Permissions::forRole('owner'));
    T::same([], Permissions::forRole('nobody'));
});

T::test('permissions: the sensitive actions are not open to manager / support / analyst', function () {
    $ownerOnly = ['payments.override', 'pricing.approve', 'ai.configure', 'integrations.manage', 'admins.manage'];
    foreach (['manager', 'support', 'analyst'] as $role) {
        foreach ($ownerOnly as $perm) {
            T::false(Permissions::can($role, $perm), "$role must not have $perm");
        }
    }
    // a manager runs the shop …
    foreach (['orders.act', 'orders.bulk', 'orders.recover', 'finance.adjust', 'ads.manage', 'alerts.manage', 'pricing.propose', 'pricing.pause'] as $perm) {
        T::true(Permissions::can('manager', $perm), "manager: $perm");
    }
    // … support only looks things up and helps customers, an analyst only reads
    foreach (['orders.act', 'orders.bulk', 'orders.recover', 'payments.review', 'finance.view', 'finance.adjust'] as $perm) {
        T::false(Permissions::can('support', $perm), "support must not have $perm");
    }
    T::true(Permissions::can('support', 'support.view'));
    foreach (array_keys(Permissions::ALL) as $perm) {
        if (str_ends_with($perm, '.view')) {
            continue;
        }
        T::false(Permissions::can('analyst', $perm), "analyst is read-only: $perm");
    }
    T::true(Permissions::can('analyst', 'finance.view') && Permissions::can('analyst', 'reports.view') && Permissions::can('analyst', 'pricing.view'));
});

T::test('permissions: every listed permission is granted to the owner and the role list is closed', function () {
    T::same(['owner', 'manager', 'support', 'analyst'], Permissions::ROLES);
    T::true(Permissions::isRole('manager'));
    T::false(Permissions::isRole('admin'));
    T::false(Permissions::isRole(''));
});

T::test('status vocabulary: the requested payment states map onto the stored ones (REFUND_PENDING does not exist)', function () {
    T::same('CREATED', Status::specPayment('creating'));
    T::same('PENDING', Status::specPayment('pending'));
    T::same('PAID', Status::specPayment('success'));
    T::same('FAILED', Status::specPayment('failed'));
    T::same('EXPIRED', Status::specPayment('expired'));
    T::same('REVIEW_REQUIRED', Status::specPayment('mismatch'));
    T::same('REFUNDED', Status::specPayment('refunded'));
    T::same('REFUNDED', Status::specPayment('partially_refunded'));
    foreach (['creating', 'pending', 'success', 'failed', 'expired', 'mismatch', 'refunded', 'partially_refunded'] as $s) {
        T::true(Status::specPayment($s) !== 'REFUND_PENDING', 'no stored state maps to a refund that is "pending": there is no refund API');
    }
});

T::test('status vocabulary: the requested order states map onto the stored ones', function () {
    $o = static fn(string $status, array $more = []): string => Status::specOrder(['status' => $status] + $more);
    T::same('AWAITING_PAYMENT', $o('PENDING_PAYMENT'));
    T::same('PAID', $o('PAID'));
    T::same('QUEUED', $o('PAID', ['next_attempt_at' => '2026-10-15 10:00:00']), 'a paid order waiting for a retry');
    T::same('QUEUED', $o('MANUAL_PENDING'), 'the manual queue');
    T::same('SUBMITTING', $o('PROCESSING'));
    T::same('PROCESSING', $o('SUBMITTED'));
    T::same('PROCESSING', $o('IN_PROGRESS'));
    T::same('PROCESSING', $o('MANUAL_APPROVED'), 'approved is not completed');
    T::same('PARTIAL', $o('PARTIAL'));
    T::same('COMPLETED', $o('COMPLETED'));
    T::same('CANCELLED', $o('CANCELLED'));
    T::same('FAILED', $o('FAILED', ['failure_kind' => 'rejected']));
    T::same('FAILED', $o('FAILED', ['failure_kind' => 'unreachable']));
    T::same('NEEDS_REVIEW', $o('FAILED', ['failure_kind' => 'ambiguous']));
    T::same('NEEDS_REVIEW', $o('FAILED', ['failure_kind' => 'misconfigured']));
    T::same('NEEDS_REVIEW', $o('FAILED', ['recovery_status' => 'needs_review']));
    T::same('NEEDS_REVIEW', $o('FAILED', ['recovery_status' => 'escalated']));
    T::same('EXPIRED', $o('EXPIRED'), 'states the requested list has no word for keep their own name');
    T::same('REFUNDED', $o('REFUNDED'));
    T::false($o('SUBMITTED') === 'COMPLETED', 'submitting to the provider is never "completed"');
    T::false($o('MANUAL_APPROVED') === 'COMPLETED', 'approving a manual order is never "completed"');
});

T::test('jobs: error text that is stored or shown has no paths, no long tokens and no control characters', function () {
    $dirty = "Failed at " . SMB_ROOT . "/app/Services/X.php line 9 using key KEYabcdefghijklmnopqrstuvwxyz0123456789 in /var/www/html/site/index.php\n\tdone";
    $clean = Jobs::clean($dirty);
    T::false(str_contains($clean, SMB_ROOT), 'the project path is removed');
    T::false(str_contains($clean, 'KEYabcdefghijklmnopqrstuvwxyz'), 'a long opaque string (a key) is removed');
    T::false(str_contains($clean, '/var/www/html'), 'a deep path is removed');
    T::false((bool) preg_match('/[\x00-\x1F]/', $clean), 'no control characters');
    T::true(mb_strlen(Jobs::clean(str_repeat('word ', 200))) <= 300);
    T::same('3 of 40 failed', Jobs::clean('3 of 40 failed'), 'ordinary text is untouched');
});

T::test('jobs: a database error is reported as a category, never as SQL', function () {
    [$cat, $msg] = Jobs::classify(new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'smb.secret_table' doesn't exist"));
    T::same('database', $cat);
    T::false(str_contains($msg, 'SQLSTATE') || str_contains($msg, 'secret_table'), $msg);
    [$cat] = Jobs::classify(new RuntimeException('cURL error 28: Operation timed out after 5001 milliseconds'));
    T::same('network', $cat);
    [$cat] = Jobs::classify(new RuntimeException('something else broke'));
    T::same('logic', $cat);
});

T::test('jobs: the time budget is cooperative — expired() turns true once the deadline passes and stays true', function () {
    $open = new JobContext(microtime(true) + 30);
    T::false($open->expired());
    T::true($open->timeLeft() > 29);
    T::false($open->stoppedEarly());
    $over = new JobContext(microtime(true) - 1, true);
    T::true($over->dryRun);
    T::true($over->expired());
    T::true($over->stoppedEarly());
    T::same(0.0, $over->timeLeft());
    $over->note('batches', 3);
    T::same(['batches' => 3], $over->summary());
});
