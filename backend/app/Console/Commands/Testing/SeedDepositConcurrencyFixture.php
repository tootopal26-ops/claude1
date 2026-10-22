<?php

namespace App\Console\Commands\Testing;

use App\Models\DepositOrder;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Testing-only helper: seeds one user with a pending deposit order for
 * the concurrent-duplicate-webhook test (see AttemptWebhookDelivery) —
 * same rationale as SeedConcurrencyFixture: this needs real,
 * cross-process MySQL fixtures several spawned PHP processes can race
 * against, which a single in-process PHPUnit run can't exercise.
 */
#[Signature('app:seed-deposit-concurrency-fixture {amount=50.00}')]
#[Description('Seed a single user with a pending deposit order for a concurrent-duplicate-webhook test')]
class SeedDepositConcurrencyFixture extends Command
{
    public function handle(): int
    {
        $user = User::factory()->create();

        $order = DepositOrder::create([
            'user_id' => $user->id,
            'amount' => $this->argument('amount'),
            'our_order_id' => 'CONC'.Str::upper(Str::random(10)),
            'provider' => 'alpha_upi',
            'status' => 'pending',
        ]);

        $this->line(json_encode([
            'user_id' => $user->id,
            'our_order_id' => $order->our_order_id,
        ]));

        return self::SUCCESS;
    }
}
