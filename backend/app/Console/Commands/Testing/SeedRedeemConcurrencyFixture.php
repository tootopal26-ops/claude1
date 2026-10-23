<?php

namespace App\Console\Commands\Testing;

use App\Models\RedeemCampaign;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Testing-only helper: seeds one redeem campaign and several players for the
 * concurrent-redemption test (see AttemptRedeemCode). It needs real,
 * cross-process MySQL rows that several spawned PHP processes can race against,
 * which a single in-process PHPUnit run can't exercise.
 */
#[Signature('app:seed-redeem-concurrency-fixture {capacity=3} {players=10} {reward=100}')]
#[Description('Seed a redeem campaign and players for a concurrent-redemption test')]
class SeedRedeemConcurrencyFixture extends Command
{
    public function handle(): int
    {
        $staff = StaffUser::factory()->superAdmin()->create();

        $campaign = RedeemCampaign::create([
            'name' => 'Concurrency fixture',
            'code' => 'RACE2',
            'total_capacity' => (int) $this->argument('capacity'),
            'reward_coins' => (int) $this->argument('reward'),
            'expires_at' => now()->addDay(),
            'is_active' => true,
            'created_by_staff_id' => $staff->id,
        ]);

        $userIds = User::factory()->count((int) $this->argument('players'))->create()->pluck('id')->all();

        $this->line(json_encode(['campaign_id' => $campaign->id, 'code' => $campaign->code, 'user_ids' => $userIds]));

        return self::SUCCESS;
    }
}
