<?php

namespace App\Console\Commands\Testing;

use App\Domain\Settings\PlatformSettingsService;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Testing-only helper: seeds one referrer (with a known code and a capped
 * referral_max_per_referrer) for the concurrent-signup test (see
 * AttemptReferralSignup). Needs real, cross-process MySQL rows several spawned
 * PHP processes can race against, which a single in-process PHPUnit run can't
 * exercise — same approach as the redeem/tournament/wallet concurrency tests.
 */
#[Signature('app:seed-referral-concurrency-fixture {max=3}')]
#[Description('Seed a referrer with a capped referral_max_per_referrer for a concurrent-signup test')]
class SeedReferralConcurrencyFixture extends Command
{
    public function handle(): int
    {
        $staff = StaffUser::factory()->superAdmin()->create();

        $referrer = User::factory()->create();

        $settings = app(PlatformSettingsService::class);
        $settings->setMany([
            'referral_enabled' => true,
            'referral_new_player_reward' => '20.00',
            'referral_referrer_reward' => '30.00',
            'referral_max_per_referrer' => (int) $this->argument('max'),
        ], $staff);

        $this->line(json_encode(['referrer_id' => $referrer->id, 'code' => $referrer->referral_code]));

        return self::SUCCESS;
    }
}
