<?php

namespace App\Domain\Withdrawal;

use App\Domain\Notifications\NotificationService;
use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\RiskAlert;
use App\Models\StaffUser;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drives the withdrawal state machine:
 *
 *   pending -> approved -> processing -> paid
 *           \_____________> rejected       \_> failed
 *
 * Requesting a withdrawal atomically reserves the amount by debiting it
 * from eligible winnings immediately — a player can never double-spend
 * winnings that are already earmarked for payout. Rejecting or failing a
 * withdrawal releases that reservation back. "Paid" is only reachable from
 * "processing" and always requires a settlement reference, so it can
 * never be set on approval alone.
 */
class WithdrawalService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws ValidationException
     */
    public function request(User $user, string $amount, string $upiId): WithdrawalRequest
    {
        if (! is_numeric($amount) || bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Withdrawal amount must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($user, $amount, $upiId) {
            $withdrawal = WithdrawalRequest::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'upi_id' => $upiId,
                'status' => 'pending',
            ]);

            try {
                $this->wallet->adjustWinningsBalance(
                    $user,
                    bcmul($amount, '-1', 2),
                    'withdrawal_reserve',
                    "withdrawal.reserve:{$withdrawal->id}",
                    $withdrawal,
                    'Withdrawal requested',
                );
            } catch (InsufficientWalletBalanceException $exception) {
                throw ValidationException::withMessages([
                    'amount' => "Insufficient eligible winnings balance: {$exception->getMessage()}",
                ]);
            }

            AuditLog::record('withdrawal.requested', $user, $withdrawal, ['amount' => $amount]);

            $this->maybeRaiseSuspiciousPatternRiskAlert($user);

            return $withdrawal;
        });
    }

    /**
     * Fires exactly once per crossing (an exact count match, not >=), so
     * a user who keeps requesting withdrawals raises one alert, not one
     * per request after the threshold.
     */
    private function maybeRaiseSuspiciousPatternRiskAlert(User $user): void
    {
        $windowHours = (int) config('minimax.suspicious_withdrawal_window_hours');
        $threshold = (int) config('minimax.suspicious_withdrawal_count_threshold');

        $recentCount = WithdrawalRequest::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours($windowHours))
            ->count();

        if ($recentCount !== $threshold) {
            return;
        }

        RiskAlert::create([
            'type' => 'suspicious_withdrawal_pattern',
            'risk_level' => 'medium',
            'target_user_id' => $user->id,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'details' => [
                'recent_request_count' => $recentCount,
                'window_hours' => $windowHours,
            ],
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function approve(WithdrawalRequest $withdrawal, StaffUser $actor): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawal, $actor) {
            $locked = $this->lockAndAssert($withdrawal, ['pending'], 'approved');

            $locked->status = 'approved';
            $locked->reviewed_by_staff_id = $actor->id;
            $locked->reviewed_at = now();
            $locked->save();

            AuditLog::record('withdrawal.approved', $actor, $locked);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    public function reject(WithdrawalRequest $withdrawal, string $reason, StaffUser $actor): WithdrawalRequest
    {
        $locked = DB::transaction(function () use ($withdrawal, $reason, $actor) {
            $locked = $this->lockAndAssert($withdrawal, ['pending', 'approved'], 'rejected');

            $locked->status = 'rejected';
            $locked->rejection_reason = $reason;
            $locked->reviewed_by_staff_id = $actor->id;
            $locked->reviewed_at = now();
            $locked->save();

            $this->wallet->adjustWinningsBalance(
                $locked->user,
                (string) $locked->amount,
                'withdrawal_release',
                "withdrawal.release:{$locked->id}",
                $locked,
                'Withdrawal rejected',
            );

            AuditLog::record('withdrawal.rejected', $actor, $locked, ['reason' => $reason]);

            return $locked;
        });

        $this->notify($locked, 'Withdrawal rejected', "Your withdrawal request for {$locked->amount} coins was rejected: {$locked->rejection_reason}. The amount has been returned to your wallet.");

        return $locked;
    }

    /**
     * @throws ValidationException
     */
    public function markProcessing(WithdrawalRequest $withdrawal, StaffUser $actor): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawal, $actor) {
            $locked = $this->lockAndAssert($withdrawal, ['approved'], 'processing');

            $locked->status = 'processing';
            $locked->save();

            AuditLog::record('withdrawal.processing', $actor, $locked);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    public function markPaid(WithdrawalRequest $withdrawal, string $settlementReference, StaffUser $actor): WithdrawalRequest
    {
        if (trim($settlementReference) === '') {
            throw ValidationException::withMessages([
                'settlement_reference' => 'A settlement reference is required to mark a withdrawal as paid.',
            ]);
        }

        $locked = DB::transaction(function () use ($withdrawal, $settlementReference, $actor) {
            $locked = $this->lockAndAssert($withdrawal, ['processing'], 'paid');

            $locked->status = 'paid';
            $locked->settlement_reference = $settlementReference;
            $locked->settled_by_staff_id = $actor->id;
            $locked->settled_at = now();
            $locked->save();

            AuditLog::record('withdrawal.paid', $actor, $locked, ['settlement_reference' => $settlementReference]);

            return $locked;
        });

        $this->notify($locked, 'Withdrawal paid', "Your withdrawal of {$locked->amount} coins has been paid.");

        return $locked;
    }

    /**
     * @throws ValidationException
     */
    public function markFailed(WithdrawalRequest $withdrawal, string $reason, StaffUser $actor): WithdrawalRequest
    {
        $locked = DB::transaction(function () use ($withdrawal, $reason, $actor) {
            $locked = $this->lockAndAssert($withdrawal, ['processing'], 'failed');

            $locked->status = 'failed';
            $locked->failure_reason = $reason;
            $locked->save();

            $this->wallet->adjustWinningsBalance(
                $locked->user,
                (string) $locked->amount,
                'withdrawal_release',
                "withdrawal.release:{$locked->id}",
                $locked,
                'Withdrawal failed',
            );

            AuditLog::record('withdrawal.failed', $actor, $locked, ['reason' => $reason]);

            return $locked;
        });

        $this->notify($locked, 'Withdrawal failed', "Your withdrawal of {$locked->amount} coins failed: {$locked->failure_reason}. The amount has been returned to your wallet.");

        return $locked;
    }

    /**
     * @param  list<string>  $allowedFrom
     *
     * @throws ValidationException
     */
    private function lockAndAssert(WithdrawalRequest $withdrawal, array $allowedFrom, string $to): WithdrawalRequest
    {
        /** @var WithdrawalRequest $locked */
        $locked = WithdrawalRequest::query()->lockForUpdate()->findOrFail($withdrawal->id);

        if (! in_array($locked->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot move a withdrawal from \"{$locked->status}\" to \"{$to}\".",
            ]);
        }

        return $locked;
    }

    private function notify(WithdrawalRequest $withdrawal, string $title, string $body): void
    {
        $this->notifications->notify(
            recipients: [$withdrawal->user],
            type: "withdrawal.{$withdrawal->status}",
            title: $title,
            body: $body,
            data: ['withdrawal_id' => (string) $withdrawal->id],
            idempotencyKey: "withdrawal.notify:{$withdrawal->id}:{$withdrawal->status}",
        );
    }
}
