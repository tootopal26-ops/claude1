<?php

namespace Tests\Feature\Ads;

use App\Domain\Ads\RewardedClaimService;
use App\Models\DailyRewardClaim;
use App\Models\RewardedClaim;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyRewardTest extends TestCase
{
    use ConfiguresAds;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAds();
        config(['minimax.daily_reward_enabled' => true, 'minimax.daily_reward_min_coins' => '0.25', 'minimax.daily_reward_max_coins' => '5.00']);
    }

    private function openDailyClaim(): string
    {
        return $this->postJson('/api/v1/ads/claims', ['purpose' => 'daily_reward'])->assertCreated()->json('data.token');
    }

    public function test_49_99_of_successful_deposits_is_not_enough_and_exactly_50_is(): void
    {
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $this->deposit($player, '49.99');
        $this->getJson('/api/v1/daily-reward')
            ->assertJsonPath('data.show_popup', false)
            ->assertJsonPath('data.eligibility.total', '49.99')
            ->assertJsonPath('data.eligibility.remaining', '0.01');
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'daily_reward'])->assertUnprocessable();

        $this->deposit($player, '0.01');
        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.show_popup', true)->assertJsonPath('data.eligibility.eligible', true);
    }

    public function test_pending_failed_and_expired_deposits_do_not_count(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '100.00', 'pending');
        $this->deposit($player, '100.00', 'failed');
        $this->deposit($player, '100.00', 'expired');
        Sanctum::actingAs($player);

        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.eligibility.total', '0.00')->assertJsonPath('data.show_popup', false);
    }

    public function test_a_verified_ad_credits_the_deposit_wallet_exactly_once_within_the_range(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        $token = $this->openDailyClaim();
        $amount = (string) DailyRewardClaim::where('user_id', $player->id)->value('amount');
        $this->assertTrue(bccomp($amount, '0.25', 2) >= 0 && bccomp($amount, '5.00', 2) <= 0);

        // Nothing until the ad network confirms.
        $this->assertSame(0, WalletLedgerEntry::where('type', 'daily_login_reward')->count());

        $params = $this->signedCallback($token, 'daily-1');
        $this->sendCallback($params)->assertOk();
        $this->sendCallback($params)->assertOk();

        $entry = WalletLedgerEntry::where('type', 'daily_login_reward')->sole();
        $this->assertSame('deposit', $entry->balance);
        $this->assertSame($amount, (string) $entry->amount);
        $this->getJson("/api/v1/ads/claims/{$token}")->assertJsonPath('data.status', 'verified')->assertJsonPath('data.reward_coins', $amount);
        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.claimed_today', true)->assertJsonPath('data.show_popup', false);
    }

    public function test_the_amount_is_fixed_for_the_day_across_retries(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '60.00');
        Sanctum::actingAs($player);

        $first = $this->openDailyClaim();
        $amount = (string) DailyRewardClaim::sole()->amount;
        $this->postJson("/api/v1/ads/claims/{$first}/cancel")->assertOk();

        $this->openDailyClaim();
        $this->assertSame(1, DailyRewardClaim::count());
        $this->assertSame($amount, (string) DailyRewardClaim::sole()->amount);
        $this->assertSame(0, WalletLedgerEntry::where('type', 'daily_login_reward')->count());
    }

    public function test_a_second_claim_the_same_day_is_refused_and_two_racing_claims_credit_once(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        // Two claims opened before either completes (as two devices might).
        $first = $this->openDailyClaim();
        $second = RewardedClaim::create([
            'token' => str_repeat('b', 32), 'user_id' => $player->id, 'purpose' => 'daily_reward', 'placement_key' => 'daily_login',
            'daily_reward_claim_id' => DailyRewardClaim::sole()->id, 'status' => 'pending', 'expires_at' => now()->addMinutes(15),
        ]);

        $this->sendCallback($this->signedCallback($first, 'daily-a'))->assertOk();
        $this->sendCallback($this->signedCallback($second->token, 'daily-b'))->assertOk();

        $this->assertSame(1, WalletLedgerEntry::where('type', 'daily_login_reward')->count());
        $this->assertSame('already_claimed_today', $second->fresh()->failure_reason);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'daily_reward'])->assertUnprocessable();
    }

    public function test_a_new_day_allows_a_new_claim(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        $this->sendCallback($this->signedCallback($this->openDailyClaim(), 'day-1'))->assertOk();
        $this->travel(1)->days();
        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.show_popup', true);
        $this->sendCallback($this->signedCallback($this->openDailyClaim(), 'day-2'))->assertOk();

        $this->assertSame(2, WalletLedgerEntry::where('type', 'daily_login_reward')->count());
    }

    public function test_dont_show_again_hides_today_only_and_claims_nothing(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        $this->postJson('/api/v1/daily-reward/dismiss')->assertOk()->assertJsonPath('data.show_popup', false)->assertJsonPath('data.dismissed_today', true);
        $this->assertSame(0, WalletLedgerEntry::where('type', 'daily_login_reward')->count());
        $this->assertSame(0, DailyRewardClaim::count());

        $this->travel(1)->days();
        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.show_popup', true)->assertJsonPath('data.dismissed_today', false);
    }

    public function test_closing_without_dont_show_again_keeps_offering_the_popup(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        // Closing the popup with X sends nothing to the server, so it is offered again.
        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.show_popup', true);
        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.show_popup', true);
    }

    public function test_the_day_is_the_admin_timezone_day_not_the_server_day(): void
    {
        config(['minimax.daily_reward_timezone' => 'Asia/Kolkata']);
        $this->travelTo(now()->utc()->setDate(2026, 10, 23)->setTime(19, 0)); // 00:30 on the 24th in India
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.day', '2026-10-24');
    }

    public function test_without_the_ad_requirement_the_claim_credits_once_directly(): void
    {
        config(['minimax.daily_reward_requires_ad' => false]);
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        $this->postJson('/api/v1/daily-reward/claim')->assertOk();
        $this->postJson('/api/v1/daily-reward/claim')->assertUnprocessable();
        $this->assertSame(1, WalletLedgerEntry::where('type', 'daily_login_reward')->count());
    }

    public function test_with_the_ad_requirement_the_direct_claim_is_refused(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        $this->postJson('/api/v1/daily-reward/claim')->assertUnprocessable();
        $this->assertSame(0, WalletLedgerEntry::where('type', 'daily_login_reward')->count());
    }

    public function test_the_reward_is_not_offered_when_the_campaign_is_off_or_outside_its_dates(): void
    {
        $player = User::factory()->create();
        $this->deposit($player, '50.00');
        Sanctum::actingAs($player);

        config(['minimax.daily_reward_enabled' => false]);
        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.show_popup', false);

        config(['minimax.daily_reward_enabled' => true, 'minimax.daily_reward_ends_on' => now()->subDays(2)->toDateString()]);
        $this->getJson('/api/v1/daily-reward')->assertJsonPath('data.show_popup', false);
        $this->assertInstanceOf(RewardedClaimService::class, app(RewardedClaimService::class));
    }
}
