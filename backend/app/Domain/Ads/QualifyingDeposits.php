<?php

namespace App\Domain\Ads;

use App\Models\DepositOrder;
use App\Models\User;

/**
 * The deposit total the ad rewards are gated on (50 by default), from the authoritative
 * deposit orders only: payments the provider confirmed (`success`). Pending, failed and
 * expired orders do not count, and nothing else does either — not redeem-code coins, not
 * rewards, not winnings, not entry-fee refunds — even though some of those also land in
 * the Deposit balance. Deposit orders have no refund or chargeback state today; if one is
 * added, it must be excluded here.
 */
class QualifyingDeposits
{
    public function totalFor(User $user): string
    {
        $sum = DepositOrder::query()->where('user_id', $user->id)->where('status', 'success')->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * @return array{total: string, required: string, remaining: string, eligible: bool}
     */
    public function progress(User $user, string $required): array
    {
        $total = $this->totalFor($user);
        $remaining = bccomp($total, $required, 2) >= 0 ? '0.00' : bcsub($required, $total, 2);

        return [
            'total' => $total,
            'required' => $required,
            'remaining' => $remaining,
            'eligible' => bccomp($total, $required, 2) >= 0,
        ];
    }
}
