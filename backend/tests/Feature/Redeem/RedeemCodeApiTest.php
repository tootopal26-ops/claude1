<?php

namespace Tests\Feature\Redeem;

use App\Domain\Redeem\RedeemCodeGenerator;
use App\Domain\Wallet\WalletService;
use App\Models\RedeemAttempt;
use App\Models\RedeemCampaign;
use App\Models\RedeemRedemption;
use App\Models\StaffUser;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RedeemCodeApiTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(array $overrides = []): RedeemCampaign
    {
        $campaign = new RedeemCampaign;
        $campaign->forceFill(array_merge([
            'name' => 'Launch',
            'code' => 'K7P4X',
            'total_capacity' => 3,
            'reward_coins' => 100,
            'expires_at' => now()->addDay(),
            'is_active' => true,
            'created_by_staff_id' => StaffUser::factory()->superAdmin()->create()->id,
        ], $overrides))->save();

        return $campaign;
    }

    private function player(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_valid_code_credits_the_deposit_balance_through_the_ledger_and_returns_the_new_balance(): void
    {
        $campaign = $this->campaign();
        $user = $this->player();

        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])
            ->assertOk()
            ->assertJsonPath('data.coins', 100)
            ->assertJsonPath('data.balance.deposit_balance', '100.00')
            ->assertJsonPath('data.balance.eligible_winnings_balance', '0.00')
            ->assertJsonPath('data.balance.withdrawable_balance', '0.00');

        $wallet = $user->fresh()->wallet;
        $this->assertSame('100.00', $wallet->deposit_balance);

        $entry = WalletLedgerEntry::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('deposit', $entry->balance);
        $this->assertSame('100.00', $entry->amount);
        $this->assertSame("redeem:{$campaign->id}:{$user->id}", $entry->idempotency_key);

        $this->assertSame(1, $campaign->fresh()->redeemed_count);
        $this->assertDatabaseHas('redeem_redemptions', ['redeem_campaign_id' => $campaign->id, 'user_id' => $user->id, 'coins' => 100, 'wallet_ledger_entry_id' => $entry->id]);
        $this->assertDatabaseHas('redeem_attempts', ['user_id' => $user->id, 'result' => 'success', 'submitted_code' => 'K7P4X']);
    }

    public function test_the_code_is_normalized_so_pasting_or_lowercase_works(): void
    {
        $this->campaign();
        $this->player();

        $this->postJson('/api/v1/redeem', ['code' => ' k7p-4x '])->assertOk();
    }

    public function test_the_reward_always_comes_from_the_campaign_never_from_the_request(): void
    {
        $this->campaign(['reward_coins' => 25]);
        $user = $this->player();

        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X', 'coins' => 999999, 'amount' => 999999, 'reward' => 999999])
            ->assertOk()
            ->assertJsonPath('data.coins', 25);

        $this->assertSame('25.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_a_player_cannot_redeem_the_same_campaign_twice(): void
    {
        $this->campaign();
        $user = $this->player();

        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertOk();
        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertStatus(422)->assertJsonPath('error', 'already_redeemed');

        $this->assertSame('100.00', $user->fresh()->wallet->deposit_balance);
        $this->assertSame(1, RedeemRedemption::count());
        $this->assertSame(1, WalletLedgerEntry::where('type', 'deposit')->where('user_id', $user->id)->count());
    }

    public function test_the_database_itself_refuses_a_second_redemption_by_the_same_player(): void
    {
        $campaign = $this->campaign();
        $user = User::factory()->create();
        RedeemRedemption::create(['redeem_campaign_id' => $campaign->id, 'user_id' => $user->id, 'coins' => 100]);

        $this->expectException(UniqueConstraintViolationException::class);

        RedeemRedemption::create(['redeem_campaign_id' => $campaign->id, 'user_id' => $user->id, 'coins' => 100]);
    }

    public function test_every_failure_reports_a_specific_error_and_credits_nothing(): void
    {
        $this->campaign(['code' => 'EXPR2', 'expires_at' => now()->subMinute()]);
        $this->campaign(['code' => 'OFF22', 'is_active' => false]);
        $this->campaign(['code' => 'FULL2', 'total_capacity' => 1, 'redeemed_count' => 1]);
        $user = $this->player();

        $this->postJson('/api/v1/redeem', ['code' => 'ABCDE'])->assertStatus(422)->assertJsonPath('error', 'invalid_code');
        $this->postJson('/api/v1/redeem', ['code' => 'AB'])->assertStatus(422)->assertJsonPath('error', 'invalid_format');
        $this->postJson('/api/v1/redeem', ['code' => 'EXPR2'])->assertStatus(422)->assertJsonPath('error', 'expired');
        $this->postJson('/api/v1/redeem', ['code' => 'OFF22'])->assertStatus(422)->assertJsonPath('error', 'inactive');
        $this->postJson('/api/v1/redeem', ['code' => 'FULL2'])->assertStatus(422)->assertJsonPath('error', 'limit_reached');

        $this->assertSame('0.00', (string) $user->fresh()->wallet?->deposit_balance ?: '0.00');
        $this->assertSame(0, RedeemRedemption::count());
        $this->assertSame(5, RedeemAttempt::where('user_id', $user->id)->count(), 'failed attempts are kept in the history');
    }

    public function test_an_archived_campaign_cannot_be_redeemed(): void
    {
        $this->campaign(['archived_at' => now()]);
        $this->player();

        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertStatus(422)->assertJsonPath('error', 'inactive');
    }

    public function test_the_capacity_is_the_number_of_successful_players(): void
    {
        $this->campaign(['total_capacity' => 2]);

        foreach ([1, 2] as $_) {
            $this->player();
            $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertOk();
        }

        $third = $this->player();
        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertStatus(422)->assertJsonPath('error', 'limit_reached');

        $this->assertSame(2, RedeemRedemption::count());
        $this->assertSame(2, RedeemCampaign::first()->redeemed_count);
        $this->assertNull($third->fresh()->wallet?->deposit_balance === '0.00' ? null : $third->fresh()->wallet?->deposit_balance);
    }

    public function test_a_banned_player_is_not_eligible(): void
    {
        $this->campaign();
        $user = User::factory()->create();
        $user->forceFill(['is_banned' => true])->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertForbidden()->assertJsonPath('error', 'ineligible');
        $this->assertSame(0, RedeemRedemption::count());
    }

    public function test_it_requires_a_signed_in_player_and_a_code(): void
    {
        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertUnauthorized();

        $this->player();
        $this->postJson('/api/v1/redeem', [])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/redeem', ['code' => ['K7P4X']])->assertStatus(422);
    }

    public function test_repeated_wrong_codes_lock_the_player_out_for_a_while(): void
    {
        $user = $this->player();

        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/redeem', ['code' => 'ZZZ'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)])->assertStatus(422);
        }

        $this->campaign();
        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'too_many_attempts');

        $this->assertSame(0, RedeemRedemption::count(), 'even a correct code is refused while locked out');

        RateLimiter::clear('redeem-strikes:'.$user->id);
        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertOk();
    }

    public function test_asking_for_a_code_you_already_hold_does_not_count_as_guessing(): void
    {
        $this->campaign();
        $this->player();
        $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertOk();

        foreach (range(1, 9) as $_) {
            $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->assertStatus(422)->assertJsonPath('error', 'already_redeemed');
        }
    }

    public function test_the_endpoint_is_rate_limited_per_minute(): void
    {
        $this->player();
        $statuses = [];

        foreach (range(1, 14) as $_) {
            $statuses[] = $this->postJson('/api/v1/redeem', ['code' => 'K7P4X'])->status();
        }

        $this->assertContains(429, $statuses);
    }

    public function test_generated_codes_are_five_characters_from_the_unambiguous_alphabet(): void
    {
        $generator = new RedeemCodeGenerator;

        foreach (range(1, 500) as $_) {
            $code = $generator->generate();

            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}$/', $code);
            $this->assertStringNotContainsString('O', $code);
            $this->assertStringNotContainsString('0', $code);
            $this->assertStringNotContainsString('I', $code);
            $this->assertStringNotContainsString('1', $code);
        }

        $this->assertGreaterThan(450, count(array_unique(array_map(fn () => $generator->generate(), range(1, 500)))), 'codes are random, not sequential or repeating');
    }

    public function test_normalize_accepts_only_five_letters_or_digits(): void
    {
        $this->assertSame('K7P4X', RedeemCodeGenerator::normalize(' k7-p4 x '));
        $this->assertNull(RedeemCodeGenerator::normalize('K7P4'));
        $this->assertNull(RedeemCodeGenerator::normalize('K7P4XY'));
        $this->assertNull(RedeemCodeGenerator::normalize('----'));
        $this->assertNull(RedeemCodeGenerator::normalize(''));
    }

    public function test_a_failure_in_the_wallet_rolls_back_the_redemption_entirely(): void
    {
        $campaign = $this->campaign();
        $user = $this->player();

        $wallet = \Mockery::mock(WalletService::class);
        $wallet->shouldReceive('creditDeposit')->andThrow(new \RuntimeException('ledger unavailable'));
        $this->app->instance(WalletService::class, $wallet);

        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/redeem', ['code' => 'K7P4X']);
            $this->fail('expected the wallet failure to surface');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, RedeemRedemption::count());
        $this->assertSame(0, $campaign->fresh()->redeemed_count);
        $this->assertDatabaseHas('redeem_attempts', ['user_id' => $user->id, 'result' => 'error']);
    }
}
