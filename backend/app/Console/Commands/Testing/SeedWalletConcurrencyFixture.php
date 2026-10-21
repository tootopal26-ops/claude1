<?php

namespace App\Console\Commands\Testing;

use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Testing-only helper: seeds one user with a fixed deposit balance for the
 * concurrent-debit test (see AttemptWalletDebit) — same rationale as
 * SeedConcurrencyFixture for tournament joins: this needs real,
 * cross-process MySQL fixtures that several spawned PHP processes can
 * race against, which a single in-process PHPUnit run can't exercise.
 */
#[Signature('app:seed-wallet-concurrency-fixture {balance=100.00}')]
#[Description('Seed a single user with a fixed deposit balance for a concurrent-debit test')]
class SeedWalletConcurrencyFixture extends Command
{
    public function handle(WalletService $wallet): int
    {
        $user = User::factory()->create();
        $wallet->creditDeposit($user, $this->argument('balance'), 'seed:'.$user->id);

        $this->line(json_encode(['user_id' => $user->id]));

        return self::SUCCESS;
    }
}
