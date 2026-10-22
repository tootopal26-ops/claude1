<?php

namespace App\Domain\StaffSalary;

use App\Models\AuditLog;
use App\Models\StaffSalarySettlement;
use App\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a settlement paid — always requires a payment reference (proof the
 * real-world transfer happened), mirroring how Withdrawals never reach
 * "paid" on approval alone. Idempotent: calling this twice on an
 * already-paid settlement is a no-op rather than overwriting who paid it
 * or when.
 */
class MarkStaffSalarySettlementPaidAction
{
    /**
     * @throws ValidationException
     */
    public function execute(StaffSalarySettlement $settlement, string $paymentReference, StaffUser $actor): StaffSalarySettlement
    {
        if (trim($paymentReference) === '') {
            throw ValidationException::withMessages([
                'payment_reference' => 'A payment reference is required to mark a settlement as paid.',
            ]);
        }

        return DB::transaction(function () use ($settlement, $paymentReference, $actor) {
            /** @var StaffSalarySettlement $locked */
            $locked = StaffSalarySettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($locked->isPaid()) {
                return $locked;
            }

            $locked->status = 'paid';
            $locked->payment_reference = $paymentReference;
            $locked->paid_by_staff_id = $actor->id;
            $locked->paid_at = now();
            $locked->save();

            AuditLog::record('staff_salary.settlement_paid', $actor, $locked, [
                'payment_reference' => $paymentReference,
                'total_amount' => (string) $locked->total_amount,
            ]);

            return $locked;
        });
    }
}
