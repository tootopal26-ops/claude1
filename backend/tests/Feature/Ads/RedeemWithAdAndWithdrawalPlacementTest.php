<?php

namespace Tests\Feature\Ads;

use App\Models\RedeemCampaign;
use App\Models\RedeemRedemption;
use App\Models\StaffUser;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RedeemWithAdAndWithdrawalPlacementTest extends TestCase
{
    use ConfiguresAds;
    use RefreshDatabase;

    private function campaign(array $overrides = []): RedeemCampaign
    {
        $campaign = new RedeemCampaign;
        $campaign->forceFill(array_merge([
            'name' => 'Ad code',
            'code' => 'AD7K2',
            'total_capacity' => 3,
            'reward_coins' => 25,
            'expires_at' => now()->addDay(),
            'is_active' => true,
            'requires_rewarded_ad' => true,
            'created_by_staff_id' => StaffUser::factory()->superAdmin()->create()->id,
        ], $overrides))->save();

        return $campaign;
    }

    public function test_a_code_that_needs_an_ad_credits_only_after_the_verified_callback_and_only_once(): void
    {
        $this->enableAds();
        $campaign = $this->campaign();
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $token = $this->postJson('/api/v1/redeem', ['code' => 'ad7k2'])
            ->assertStatus(202)
            ->assertJsonPath('error', 'ad_required')
            ->json('data.claim.token');
        $this->assertSame(0, WalletLedgerEntry::count());
        $this->assertSame(0, $campaign->fresh()->redeemed_count);

        $params = $this->signedCallback($token, 'redeem-1');
        $this->sendCallback($params)->assertOk();
        $this->sendCallback($params)->assertOk();

        $this->assertSame(1, RedeemRedemption::count());
        $this->assertSame(1, $campaign->fresh()->redeemed_count);
        $this->assertSame('25.00', (string) WalletLedgerEntry::sole()->amount);
        $this->postJson('/api/v1/redeem', ['code' => 'AD7K2'])->assertUnprocessable()->assertJsonPath('error', 'already_redeemed');
    }

    public function test_closing_the_ad_early_credits_nothing_and_does_not_burn_the_code(): void
    {
        $this->enableAds();
        $campaign = $this->campaign(['total_capacity' => 1]);
        Sanctum::actingAs(User::factory()->create());

        $token = $this->postJson('/api/v1/redeem', ['code' => 'AD7K2'])->assertStatus(202)->json('data.claim.token');
        $this->postJson("/api/v1/ads/claims/{$token}/cancel")->assertOk();
        $this->assertSame(0, WalletLedgerEntry::count());

        // Trying again later works: the reservation is renewed and the code is still unused.
        $retry = $this->postJson('/api/v1/redeem', ['code' => 'AD7K2'])->assertStatus(202)->json('data.claim.token');
        $this->sendCallback($this->signedCallback($retry, 'redeem-retry'))->assertOk();
        $this->assertSame(1, $campaign->fresh()->redeemed_count);
    }

    public function test_live_reservations_hold_capacity_and_lapsed_ones_release_it(): void
    {
        $this->enableAds();
        $this->campaign(['total_capacity' => 1]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/redeem', ['code' => 'AD7K2'])->assertStatus(202);

        Sanctum::actingAs($second = User::factory()->create());
        $this->postJson('/api/v1/redeem', ['code' => 'AD7K2'])->assertUnprocessable()->assertJsonPath('error', 'limit_reached');

        $this->travel(20)->minutes();
        $this->artisan('ads:maintain')->assertSuccessful();
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/redeem', ['code' => 'AD7K2'])->assertStatus(202);
    }

    public function test_when_ads_are_off_the_code_is_refused_without_consuming_it(): void
    {
        $this->campaign();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/redeem', ['code' => 'AD7K2'])->assertUnprocessable()->assertJsonPath('error', 'ads_unavailable');
        $this->assertSame(0, RedeemRedemption::count());
    }

    public function test_codes_without_the_ad_requirement_still_redeem_immediately(): void
    {
        $this->enableAds();
        $this->campaign(['requires_rewarded_ad' => false]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/redeem', ['code' => 'AD7K2'])->assertOk()->assertJsonPath('data.coins', 25);
    }

    public function test_the_post_withdrawal_placement_runs_only_once_and_only_for_a_paid_withdrawal(): void
    {
        $this->enableAds();
        $player = User::factory()->create();
        $pending = $this->withdrawal($player, 'pending');
        $failed = $this->withdrawal($player, 'failed');
        $paid = $this->withdrawal($player, 'paid');
        $paidLongAgo = $this->withdrawal($player, 'paid', now()->subDays(5));
        Sanctum::actingAs($player);

        $this->postJson("/api/v1/ads/withdrawals/{$paidLongAgo->id}/post-success")->assertOk()->assertJsonPath('data.show', false);

        $this->postJson("/api/v1/ads/withdrawals/{$pending->id}/post-success")->assertOk()->assertJsonPath('data.show', false);
        $this->postJson("/api/v1/ads/withdrawals/{$failed->id}/post-success")->assertOk()->assertJsonPath('data.show', false);
        $this->postJson("/api/v1/ads/withdrawals/{$paid->id}/post-success")->assertOk()->assertJsonPath('data.show', true);
        $this->postJson("/api/v1/ads/withdrawals/{$paid->id}/post-success")->assertOk()->assertJsonPath('data.show', false);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/ads/withdrawals/{$paid->id}/post-success")->assertNotFound();
    }

    private function withdrawal(User $user, string $status, ?Carbon $settledAt = null): WithdrawalRequest
    {
        $withdrawal = new WithdrawalRequest;
        $withdrawal->forceFill([
            'user_id' => $user->id,
            'amount' => '100.00',
            'upi_id' => 'player@upi',
            'status' => $status,
            'settled_at' => $status === 'paid' ? ($settledAt ?? now()) : null,
        ])->save();

        return $withdrawal;
    }
}
