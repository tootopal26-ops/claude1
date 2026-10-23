<?php

namespace App\Console\Commands\Testing;

use App\Domain\Wallet\WalletService;
use App\Models\SectionUnlockPrice;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Testing-only helper: prices two sections (the real `leaderboard` and a test-only
 * `arena`, see AttemptSectionUnlock) and creates funded players for the concurrent
 * section-unlock test, which needs real cross-process MySQL rows that several
 * spawned PHP processes can race against.
 */
#[Signature('app:seed-section-unlock-concurrency-fixture {players=1} {balance=150} {price=100}')]
#[Description('Seed priced sections and funded players for a concurrent section-unlock test')]
class SeedSectionUnlockConcurrencyFixture extends Command
{
    public function handle(WalletService $wallet): int
    {
        $staff = StaffUser::factory()->superAdmin()->create();

        foreach (['leaderboard', 'arena'] as $key) {
            SectionUnlockPrice::create([
                'section_key' => $key,
                'price_coins' => (int) $this->argument('price'),
                'is_enabled' => true,
                'updated_by_staff_id' => $staff->id,
            ]);
        }

        $users = User::factory()->count((int) $this->argument('players'))->create();

        foreach ($users as $user) {
            $wallet->creditDeposit($user, number_format((float) $this->argument('balance'), 2, '.', ''), "fixture:{$user->id}");
        }

        $this->line(json_encode(['user_ids' => $users->pluck('id')->all()]));

        return self::SUCCESS;
    }
}
