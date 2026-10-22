<?php

namespace App\Console\Commands\Testing;

use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Testing-only helper: seeds one user with both a deposit balance and an
 * eligible-winnings balance, for a test proving WalletService's single
 * row lock correctly serializes two *different* operations (an
 * entry-fee debit and a withdrawal request) racing against the same
 * wallet, not just the same operation racing against itself.
 */
#[Signature('app:seed-dual-balance-wallet-fixture {deposit=500.00} {winnings=500.00}')]
#[Description('Seed a single user with both a deposit and winnings balance')]
class SeedDualBalanceWalletFixture extends Command
{
    public function handle(WalletService $wallet): int
    {
        $user = User::factory()->create();
        $wallet->creditDeposit($user, $this->argument('deposit'), 'seed:deposit:'.$user->id);
        $wallet->creditWinnings($user, $this->argument('winnings'), 'seed:winnings:'.$user->id);

        $this->line(json_encode(['user_id' => $user->id]));

        return self::SUCCESS;
    }
}
