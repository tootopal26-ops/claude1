<?php

namespace Tests\Feature\Ads;

use App\Models\AdRewardCampaign;
use App\Models\RewardedClaim;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RewardCampaignTest extends TestCase
{
    use ConfiguresAds;
    use RefreshDatabase;

    private function campaign(array $overrides = []): AdRewardCampaign
    {
        return AdRewardCampaign::create(array_merge([
            'name' => 'Weekend bonus',
            'reward_coins' => '2.50',
            'daily_limit_per_user' => 2,
            'is_active' => true,
        ], $overrides));
    }

    public function test_players_see_only_running_campaigns_and_only_while_the_placement_is_live(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $running = $this->campaign();
        $this->campaign(['name' => 'Off', 'is_active' => false]);
        $this->campaign(['name' => 'Later', 'starts_at' => now()->addDay()]);
        $this->campaign(['name' => 'Over', 'ends_at' => now()->subMinute()]);

        $this->getJson('/api/v1/ads/campaigns')->assertOk()->assertExactJson(['data' => []]);

        $this->enableAds();

        $this->getJson('/api/v1/ads/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $running->id)
            ->assertJsonPath('data.0.reward_coins', '2.50')
            ->assertJsonPath('data.0.available', true);
    }

    public function test_a_verified_callback_credits_the_fixed_reward_once_and_the_daily_limit_holds(): void
    {
        $this->enableAds();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $campaign = $this->campaign(['daily_limit_per_user' => 1]);

        $token = $this->postJson('/api/v1/ads/claims', ['purpose' => 'campaign', 'campaign_id' => $campaign->id])->assertCreated()->json('data.token');

        // Nothing is credited before the network's callback.
        $this->assertSame(0, WalletLedgerEntry::where('type', 'ad_campaign_reward')->count());

        $this->sendCallback($this->signedCallback($token, 'evt-c1'))->assertOk();
        $this->sendCallback($this->signedCallback($token, 'evt-c1'))->assertOk();

        $this->assertSame(1, WalletLedgerEntry::where('type', 'ad_campaign_reward')->where('user_id', $user->id)->count());
        $this->assertSame('2.50', (string) WalletLedgerEntry::where('type', 'ad_campaign_reward')->value('amount'));
        $this->assertSame('2.50', (string) $campaign->fresh()->credited_coins);

        $this->getJson('/api/v1/ads/campaigns')->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.reason', 'daily_limit');
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'campaign', 'campaign_id' => $campaign->id])->assertUnprocessable();
    }

    public function test_a_campaign_whose_budget_runs_out_during_the_ad_grants_nothing(): void
    {
        $this->enableAds();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $campaign = $this->campaign(['budget_coins' => '2.50']);

        $token = $this->postJson('/api/v1/ads/claims', ['purpose' => 'campaign', 'campaign_id' => $campaign->id])->assertCreated()->json('data.token');
        // Another player's reward used the whole budget while this ad was playing.
        $campaign->update(['credited_coins' => '2.50']);

        $this->sendCallback($this->signedCallback($token, 'evt-c2'))->assertOk();

        $claim = RewardedClaim::where('token', $token)->firstOrFail();
        $this->assertSame('verified', $claim->status);
        $this->assertSame('budget_spent', $claim->failure_reason);
        $this->assertSame(0, WalletLedgerEntry::where('type', 'ad_campaign_reward')->count());
    }
}
