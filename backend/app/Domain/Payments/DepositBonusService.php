<?php

namespace App\Domain\Payments;

use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\DepositBonusAward;
use App\Models\DepositOrder;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The first-deposit bonus (Admin > Deposit Bonus Management). The only way a row in
 * deposit_bonus_awards is ever created is maybeAward(), called from inside
 * DepositService::markPaid()'s own locked transaction, after a deposit has already been
 * confirmed paid by the payment gateway's webhook — never reachable from any other path,
 * never from a frontend success screen, never re-verifying the payment itself.
 */
class DepositBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
    ) {}

    /**
     * Called once, synchronously, inside markPaid()'s existing lock on $order — $order is
     * already the locked row with status just set to 'success'. Returns null (no row, no
     * credit) whenever the order isn't eligible; this is the normal case, never an error.
     */
    public function maybeAward(DepositOrder $order): ?DepositBonusAward
    {
        if (! (bool) config('minimax.deposit_bonus_enabled')) {
            return null;
        }

        $minDeposit = (string) config('minimax.deposit_bonus_min_deposit');
        if (bccomp((string) $order->amount, $minDeposit, 2) < 0) {
            return null;
        }

        $firstDepositOnly = (bool) config('minimax.deposit_bonus_first_deposit_only');
        $amount = (string) config('minimax.deposit_bonus_amount');

        if (bccomp($amount, '0.00', 2) <= 0) {
            return null;
        }

        if ($firstDepositOnly) {
            if (DepositBonusAward::where('user_id', $order->user_id)->exists()) {
                return null;
            }
        } else {
            $maxPerPlayer = config('minimax.deposit_bonus_max_per_player');
            if ($maxPerPlayer !== null) {
                $alreadyAwarded = (string) DepositBonusAward::where('user_id', $order->user_id)->sum('amount');
                // A fixed per-award amount that would push the player over their lifetime
                // cap is skipped entirely rather than partially awarded — nobody asked for
                // a partial-bonus feature, and a program with a stated fixed amount per
                // award should keep meaning exactly that on every receipt.
                if (bccomp(bcadd($alreadyAwarded, $amount, 2), (string) $maxPerPlayer, 2) > 0) {
                    return null;
                }
            }
        }

        $entry = $this->wallet->creditReward(
            $order->user,
            $amount,
            'deposit_bonus',
            "deposit_bonus:{$order->our_order_id}",
            $order,
            'First deposit bonus',
        );

        $award = DepositBonusAward::create([
            'user_id' => $order->user_id,
            'deposit_order_id' => $order->id,
            'amount' => $amount,
            'wallet_ledger_entry_id' => $entry->id,
        ]);

        AuditLog::record('deposit_bonus.awarded', null, $award, [
            'deposit_order_id' => $order->id,
            'amount' => $amount,
        ]);

        return $award;
    }

    /**
     * What the app shows on the Deposit screen: the current settings, plus whether THIS
     * player could still receive one — the app must never guess this itself. "Eligible"
     * here is a best-effort read for display only; maybeAward() inside the locked
     * transaction is the only thing that actually decides it.
     *
     * @return array{enabled: bool, amount: string, min_deposit: string, eligible: bool}
     */
    public function eligibilityFor(User $user): array
    {
        $enabled = (bool) config('minimax.deposit_bonus_enabled');
        $firstDepositOnly = (bool) config('minimax.deposit_bonus_first_deposit_only');

        $eligible = $enabled;
        if ($eligible && $firstDepositOnly) {
            $eligible = ! DepositBonusAward::where('user_id', $user->id)->exists();
        }
        if ($eligible && ! $firstDepositOnly) {
            $maxPerPlayer = config('minimax.deposit_bonus_max_per_player');
            if ($maxPerPlayer !== null) {
                $alreadyAwarded = (string) DepositBonusAward::where('user_id', $user->id)->sum('amount');
                $eligible = bccomp(bcadd($alreadyAwarded, (string) config('minimax.deposit_bonus_amount'), 2), (string) $maxPerPlayer, 2) <= 0;
            }
        }

        return [
            'enabled' => $enabled,
            'amount' => (string) config('minimax.deposit_bonus_amount'),
            'min_deposit' => (string) config('minimax.deposit_bonus_min_deposit'),
            'eligible' => $eligible,
        ];
    }

    /**
     * @return array{total_amount: string, players: int}
     */
    public function stats(): array
    {
        return [
            'total_amount' => (string) DepositBonusAward::sum('amount'),
            'players' => DepositBonusAward::distinct('user_id')->count('user_id'),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, DepositBonusAward>
     */
    public function history(int $perPage = 20): LengthAwarePaginator
    {
        return DepositBonusAward::query()
            ->with(['user', 'depositOrder'])
            ->latest('id')
            ->paginate($perPage);
    }
}
