<?php

namespace App\Console\Commands\Testing;

use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:attempt-wallet-debit {user} {amount} {key}')]
#[Description('Attempt to debit a wallet for an entry fee; prints DEBITED or REJECTED')]
class AttemptWalletDebit extends Command
{
    public function handle(WalletService $wallet): int
    {
        $user = User::findOrFail($this->argument('user'));

        try {
            $wallet->debitForEntryFee($user, $this->argument('amount'), $this->argument('key'));
            $this->line('DEBITED');

            return self::SUCCESS;
        } catch (InsufficientWalletBalanceException $exception) {
            $this->line('REJECTED: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
