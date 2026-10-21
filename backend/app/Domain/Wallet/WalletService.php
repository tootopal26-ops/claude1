<?php

namespace App\Domain\Wallet;

use App\Models\AuditLog;
use App\Models\RiskAlert;
use App\Models\StaffUser;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every balance-affecting operation in the app goes through here. The
 * wallet_ledger_entries table is the source of truth; the cached columns
 * on `wallets` exist only so reads don't have to sum the ledger every
 * time (see `php artisan wallet:reconcile`, which checks they agree).
 *
 * Money is always a decimal STRING (Eloquent's `decimal:2` cast returns
 * one, never a float) and always combined with bcmath — bcadd/bcsub/
 * bccomp — never native +/-/<. A float can't represent 0.10 exactly;
 * compounding that across thousands of wallet operations is exactly the
 * kind of bug that looks fine in testing and loses real money in
 * production.
 *
 * Every method takes an idempotency key and is safe to call twice with
 * the same one: the second call returns the first call's result without
 * re-deciding anything, which matters most for debitForEntryFee (a
 * retried request must not re-split against balances that have since
 * changed) and refundExact (a retried cancellation must not refund twice).
 */
class WalletService
{
    public function getOrCreateWallet(User $user): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $user->id]);
    }

    public function creditDeposit(
        User $user,
        string $amount,
        string $idempotencyKey,
        ?Model $source = null,
        ?string $reason = null,
    ): WalletLedgerEntry {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $amount, $idempotencyKey, $source, $reason) {
            $wallet = $this->lockWallet($user);

            if ($existing = $this->findExisting($idempotencyKey, 'deposit')) {
                return $existing;
            }

            return $this->applyEntry($wallet, 'deposit', 'deposit', $amount, $idempotencyKey, $source, $reason);
        });
    }

    public function creditWinnings(
        User $user,
        string $amount,
        string $idempotencyKey,
        ?Model $source = null,
        ?string $reason = null,
    ): WalletLedgerEntry {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $amount, $idempotencyKey, $source, $reason) {
            $wallet = $this->lockWallet($user);

            if ($existing = $this->findExisting($idempotencyKey, 'winnings')) {
                return $existing;
            }

            return $this->applyEntry($wallet, 'winning', 'winnings', $amount, $idempotencyKey, $source, $reason);
        });
    }

    /**
     * Deducts $amount using the deposit balance first, then the eligible
     * winnings balance for whatever remains — the exact priority the
     * project spec requires for every entry-fee charge.
     *
     * @return array{deposit_used: string, winnings_used: string, entries: list<WalletLedgerEntry>}
     *
     * @throws InsufficientWalletBalanceException
     */
    public function debitForEntryFee(
        User $user,
        string $amount,
        string $idempotencyKey,
        ?Model $source = null,
    ): array {
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($user, $amount, $idempotencyKey, $source) {
            $wallet = $this->lockWallet($user);

            $existingEntries = WalletLedgerEntry::where('idempotency_key', $idempotencyKey)->get();

            if ($existingEntries->isNotEmpty()) {
                return $this->summarizeSplit($existingEntries);
            }

            $depositAvailable = (string) $wallet->deposit_balance;
            $winningsAvailable = (string) $wallet->eligible_winnings_balance;

            $depositUsed = bccomp($depositAvailable, $amount, 2) >= 0 ? $amount : $depositAvailable;
            $remaining = bcsub($amount, $depositUsed, 2);
            $winningsUsed = bccomp($remaining, '0.00', 2) > 0
                ? (bccomp($winningsAvailable, $remaining, 2) >= 0 ? $remaining : $winningsAvailable)
                : '0.00';

            $totalCovered = bcadd($depositUsed, $winningsUsed, 2);

            if (bccomp($totalCovered, $amount, 2) < 0) {
                throw new InsufficientWalletBalanceException(
                    "Insufficient balance: need {$amount}, have {$totalCovered} available.",
                );
            }

            $entries = [];

            if (bccomp($depositUsed, '0.00', 2) > 0) {
                $entries[] = $this->applyEntry(
                    $wallet, 'entry_fee', 'deposit', bcmul($depositUsed, '-1', 2), $idempotencyKey, $source,
                );
            }

            if (bccomp($winningsUsed, '0.00', 2) > 0) {
                $entries[] = $this->applyEntry(
                    $wallet, 'entry_fee', 'winnings', bcmul($winningsUsed, '-1', 2), $idempotencyKey, $source,
                );
            }

            return ['deposit_used' => $depositUsed, 'winnings_used' => $winningsUsed, 'entries' => $entries];
        });
    }

    /**
     * Reverses a previous debitForEntryFee exactly: the same amount,
     * restored to the same balance(s) it was originally taken from. The
     * original split is derived from that debit's own ledger rows, so a
     * caller never has to (and can't accidentally mis-) re-supply amounts.
     *
     * @return list<WalletLedgerEntry>
     *
     * @throws \RuntimeException if there is nothing recorded under $originalIdempotencyKey to refund
     */
    public function refundExact(
        User $user,
        string $originalIdempotencyKey,
        string $refundIdempotencyKey,
        ?Model $source = null,
        ?string $reason = null,
    ): array {
        return DB::transaction(function () use ($user, $originalIdempotencyKey, $refundIdempotencyKey, $source, $reason) {
            $wallet = $this->lockWallet($user);

            $alreadyRefunded = WalletLedgerEntry::where('idempotency_key', $refundIdempotencyKey)->get();
            if ($alreadyRefunded->isNotEmpty()) {
                return $alreadyRefunded->all();
            }

            $originalDebits = WalletLedgerEntry::where('idempotency_key', $originalIdempotencyKey)
                ->where('amount', '<', 0)
                ->get();

            if ($originalDebits->isEmpty()) {
                throw new \RuntimeException("No debit found for idempotency key [{$originalIdempotencyKey}] to refund.");
            }

            $entries = [];

            foreach ($originalDebits as $debit) {
                $creditAmount = bcmul((string) $debit->amount, '-1', 2);
                $entries[] = $this->applyEntry(
                    $wallet, 'refund', $debit->balance, $creditAmount, $refundIdempotencyKey, $source, $reason,
                );
            }

            return $entries;
        });
    }

    /**
     * Staff-initiated correction. Always audited; adjustments at or above
     * the configured threshold also raise a risk alert for review.
     *
     * @throws InsufficientWalletBalanceException if subtracting would take the balance negative
     */
    public function manualAdjust(
        User $user,
        string $signedAmount,
        string $balance,
        string $reason,
        StaffUser $actor,
        string $idempotencyKey,
    ): WalletLedgerEntry {
        if (! in_array($balance, ['deposit', 'winnings'], true)) {
            throw new \InvalidArgumentException("Invalid balance type [{$balance}].");
        }

        return DB::transaction(function () use ($user, $signedAmount, $balance, $reason, $actor, $idempotencyKey) {
            $wallet = $this->lockWallet($user);

            if ($existing = $this->findExisting($idempotencyKey, $balance)) {
                return $existing;
            }

            $current = $balance === 'deposit' ? (string) $wallet->deposit_balance : (string) $wallet->eligible_winnings_balance;
            $resultingBalance = bcadd($current, $signedAmount, 2);

            if (bccomp($resultingBalance, '0.00', 2) < 0) {
                throw new InsufficientWalletBalanceException(
                    "Adjustment of {$signedAmount} would take the {$balance} balance negative (current: {$current}).",
                );
            }

            $entry = $this->applyEntry(
                $wallet, 'admin_adjustment', $balance, $signedAmount, $idempotencyKey, null, $reason, $actor,
            );

            AuditLog::record('wallet.manual_adjustment', $actor, $user, [
                'balance' => $balance,
                'amount' => $signedAmount,
                'previous_balance' => $current,
                'resulting_balance' => $resultingBalance,
                'reason' => $reason,
                'ledger_entry_id' => $entry->id,
            ]);

            $threshold = (string) config('minimax.wallet_risk_alert_threshold', '200.00');
            if (bccomp(ltrim($signedAmount, '-'), $threshold, 2) >= 0) {
                RiskAlert::create([
                    'type' => 'manual_wallet_adjustment_over_threshold',
                    'risk_level' => 'high',
                    'actor_staff_id' => $actor->id,
                    'target_user_id' => $user->id,
                    'details' => [
                        'balance' => $balance,
                        'amount' => $signedAmount,
                        'previous_balance' => $current,
                        'resulting_balance' => $resultingBalance,
                        'reason' => $reason,
                        'ledger_entry_id' => $entry->id,
                    ],
                ]);
            }

            return $entry;
        });
    }

    private function lockWallet(User $user): Wallet
    {
        return Wallet::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);
    }

    private function findExisting(string $idempotencyKey, string $balance): ?WalletLedgerEntry
    {
        return WalletLedgerEntry::where('idempotency_key', $idempotencyKey)->where('balance', $balance)->first();
    }

    /**
     * @return array{deposit_used: string, winnings_used: string, entries: list<WalletLedgerEntry>}
     */
    private function summarizeSplit($existingEntries): array
    {
        $depositEntry = $existingEntries->firstWhere('balance', 'deposit');
        $winningsEntry = $existingEntries->firstWhere('balance', 'winnings');

        return [
            'deposit_used' => $depositEntry ? bcmul((string) $depositEntry->amount, '-1', 2) : '0.00',
            'winnings_used' => $winningsEntry ? bcmul((string) $winningsEntry->amount, '-1', 2) : '0.00',
            'entries' => $existingEntries->all(),
        ];
    }

    private function applyEntry(
        Wallet $wallet,
        string $type,
        string $balance,
        string $signedAmount,
        string $idempotencyKey,
        ?Model $source = null,
        ?string $reason = null,
        ?StaffUser $createdByStaff = null,
    ): WalletLedgerEntry {
        $column = $balance === 'deposit' ? 'deposit_balance' : 'eligible_winnings_balance';
        $newBalance = bcadd((string) $wallet->{$column}, $signedAmount, 2);

        $entry = WalletLedgerEntry::create([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'type' => $type,
            'balance' => $balance,
            'amount' => $signedAmount,
            'balance_after' => $newBalance,
            'reason' => $reason,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'created_by_staff_id' => $createdByStaff?->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        $wallet->{$column} = $newBalance;
        $wallet->save();

        return $entry;
    }

    private function assertPositiveAmount(string $amount): void
    {
        if (! is_numeric($amount) || bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException("Amount must be a positive decimal string, got [{$amount}].");
        }
    }
}
