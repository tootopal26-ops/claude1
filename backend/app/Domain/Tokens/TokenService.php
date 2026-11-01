<?php

namespace App\Domain\Tokens;

use App\Models\TokenLedgerEntry;
use App\Models\User;
use App\Models\UserTokenBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every balance-affecting operation on spin tokens goes through here — the same shape as
 * WalletService, scaled down to plain integers (every token amount in this app, a spin
 * reward or an exchange tier, is a whole number, so there's no need for WalletService's
 * decimal/bcmath machinery). token_ledger_entries is the source of truth; the cached
 * balance column on user_token_balances exists only so reads don't have to sum the ledger.
 *
 * Every method is idempotent on its caller-supplied key: a retried call with the same key
 * returns the original entry instead of moving the balance again.
 */
class TokenService
{
    public function getOrCreateBalance(User $user): UserTokenBalance
    {
        return UserTokenBalance::firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
    }

    public function credit(
        User $user,
        int $amount,
        string $type,
        string $idempotencyKey,
        ?Model $source = null,
        ?string $reason = null,
    ): TokenLedgerEntry {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Credit amount must be positive, got [{$amount}].");
        }

        return DB::transaction(function () use ($user, $amount, $type, $idempotencyKey, $source, $reason) {
            $balance = $this->lockBalance($user);

            if ($existing = TokenLedgerEntry::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            return $this->applyEntry($balance, $type, $amount, $idempotencyKey, $source, $reason);
        });
    }

    /**
     * @throws InsufficientTokenBalanceException
     */
    public function debit(
        User $user,
        int $amount,
        string $type,
        string $idempotencyKey,
        ?Model $source = null,
        ?string $reason = null,
    ): TokenLedgerEntry {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Debit amount must be positive, got [{$amount}].");
        }

        return DB::transaction(function () use ($user, $amount, $type, $idempotencyKey, $source, $reason) {
            $balance = $this->lockBalance($user);

            if ($existing = TokenLedgerEntry::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            if ($balance->balance < $amount) {
                throw new InsufficientTokenBalanceException(
                    "Insufficient tokens: need {$amount}, have {$balance->balance}.",
                );
            }

            return $this->applyEntry($balance, $type, -$amount, $idempotencyKey, $source, $reason);
        });
    }

    private function lockBalance(User $user): UserTokenBalance
    {
        return UserTokenBalance::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
    }

    private function applyEntry(
        UserTokenBalance $balance,
        string $type,
        int $signedAmount,
        string $idempotencyKey,
        ?Model $source,
        ?string $reason,
    ): TokenLedgerEntry {
        $newBalance = $balance->balance + $signedAmount;

        $entry = TokenLedgerEntry::create([
            'user_id' => $balance->user_id,
            'type' => $type,
            'amount' => $signedAmount,
            'balance_after' => $newBalance,
            'reason' => $reason,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'idempotency_key' => $idempotencyKey,
        ]);

        $balance->balance = $newBalance;
        $balance->save();

        return $entry;
    }
}
