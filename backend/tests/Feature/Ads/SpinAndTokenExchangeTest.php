<?php

namespace Tests\Feature\Ads;

use App\Domain\Spin\SpinService;
use App\Models\SpinAttempt;
use App\Models\TokenExchangeTransaction;
use App\Models\TokenLedgerEntry;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpinAndTokenExchangeTest extends TestCase
{
    use ConfiguresAds;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAds(['spin', 'token_exchange']);
        config(['minimax.spin_enabled' => true]);

        // Earning enough tokens for an exchange can take many real spins (one ad claim +
        // one verification callback each). Sanctum::actingAs makes $request->user() resolve
        // even on the callback route (which carries no auth middleware in production), so in
        // this test environment only, its hits land in the same /ads/claims throttle bucket.
        // Not a production concern — the throttle itself isn't what these tests exercise.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function openClaim(array $payload): string
    {
        return $this->postJson('/api/v1/ads/claims', $payload)->assertCreated()->json('data.token');
    }

    public function test_the_weighted_draw_matches_the_configured_percentages_statistically(): void
    {
        $service = app(SpinService::class);
        $probabilities = [1 => '80.00', 2 => '10.00', 3 => '5.00', 4 => '3.00', 5 => '2.00'];

        $counts = array_fill_keys([1, 2, 3, 4, 5], 0);
        for ($i = 0; $i < 20000; $i++) {
            $counts[$service->draw($probabilities)]++;
        }

        // Generous tolerance (±2 points) — this guards against a broken weighting scheme,
        // not sampling noise.
        $this->assertEqualsWithDelta(80.0, $counts[1] / 200, 2.0);
        $this->assertEqualsWithDelta(10.0, $counts[2] / 200, 2.0);
        $this->assertEqualsWithDelta(5.0, $counts[3] / 200, 2.0);
        $this->assertEqualsWithDelta(3.0, $counts[4] / 200, 2.0);
        $this->assertEqualsWithDelta(2.0, $counts[5] / 200, 2.0);
    }

    public function test_spin_status_reports_the_real_balance_and_the_fixed_segments(): void
    {
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $this->getJson('/api/v1/spin')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.balance', 0)
            ->assertJsonCount(8, 'data.segments');
    }

    public function test_spin_is_refused_while_disabled_and_no_claim_is_opened(): void
    {
        config(['minimax.spin_enabled' => false]);
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $this->getJson('/api/v1/spin')->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'spin'])->assertUnprocessable();
        $this->assertSame(0, SpinAttempt::count());
    }

    public function test_a_verified_spin_ad_credits_tokens_exactly_once_and_never_before_verification(): void
    {
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $token = $this->openClaim(['purpose' => 'spin']);
        $this->assertSame(0, SpinAttempt::count());
        $this->assertSame(0, TokenLedgerEntry::count());

        $params = $this->signedCallback($token, 'spin-1');
        $this->sendCallback($params)->assertOk();
        $this->sendCallback($params)->assertOk(); // duplicate network callback

        $attempt = SpinAttempt::sole();
        $this->assertSame(1, TokenLedgerEntry::count());
        $this->assertContains($attempt->winning_number, [1, 2, 3, 4, 5]);
        $this->assertSame($attempt->winning_number, $attempt->tokens_awarded);

        $this->getJson('/api/v1/tokens')->assertJsonPath('data.balance', $attempt->tokens_awarded);
        $this->getJson('/api/v1/spin')->assertJsonPath('data.balance', $attempt->tokens_awarded);
    }

    public function test_exchange_tiers_match_the_fixed_spec_table(): void
    {
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $response = $this->getJson('/api/v1/exchange/tiers')->assertOk();
        $tiers = $response->json('data');

        $this->assertCount(10, $tiers);
        $this->assertSame(['index' => 0, 'tokens' => 50, 'coins' => '1.00', 'bonus_percent' => '0.00'], $tiers[0]);
        $this->assertSame(['index' => 4, 'tokens' => 1000, 'coins' => '21.00', 'bonus_percent' => '5.00'], $tiers[4]);
        $this->assertSame(['index' => 9, 'tokens' => 20000, 'coins' => '420.00', 'bonus_percent' => '5.00'], $tiers[9]);
    }

    public function test_an_exchange_is_refused_before_any_ad_when_tokens_are_insufficient(): void
    {
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $this->postJson('/api/v1/ads/claims', ['purpose' => 'token_exchange', 'exchange_tier' => 0])->assertUnprocessable();
        $this->assertSame(0, TokenExchangeTransaction::count());
    }

    public function test_a_verified_exchange_debits_tokens_and_credits_the_deposit_wallet_exactly_once(): void
    {
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        // Earn exactly 50 tokens via real, verified spins (never set a balance directly) —
        // the odds are pinned to always roll a 5 so this takes exactly 10 ad claims, well
        // under the claims-endpoint's rate limit.
        config(['minimax.spin_prob_1' => '0.00', 'minimax.spin_prob_2' => '0.00', 'minimax.spin_prob_3' => '0.00', 'minimax.spin_prob_4' => '0.00', 'minimax.spin_prob_5' => '100.00']);
        for ($i = 0; $i < 10; $i++) {
            $token = $this->openClaim(['purpose' => 'spin']);
            $this->sendCallback($this->signedCallback($token, 'spin-'.$i));
        }
        $balance = SpinAttempt::sum('tokens_awarded');
        $this->assertSame(50, $balance);

        $exchangeToken = $this->openClaim(['purpose' => 'token_exchange', 'exchange_tier' => 0]);

        $params = $this->signedCallback($exchangeToken, 'exchange-1');
        $this->sendCallback($params)->assertOk();
        $this->sendCallback($params)->assertOk(); // duplicate network callback

        $tx = TokenExchangeTransaction::sole();
        $this->assertSame('credited', $tx->status);
        $this->assertSame(50, $tx->tokens_required);
        $this->assertSame('1.00', (string) $tx->coins_received);

        $this->assertSame(1, WalletLedgerEntry::where('type', 'ad_reward')->count());
        $this->assertSame('1.00', (string) WalletLedgerEntry::where('type', 'ad_reward')->sole()->amount);
        $this->assertSame($balance - 50, $this->getJson('/api/v1/tokens')->json('data.balance'));

        $this->getJson('/api/v1/exchange/history')->assertJsonPath('data.0.status', 'credited')
            ->assertJsonPath('data.0.tokens_required', 50)
            ->assertJsonPath('data.0.coins_received', '1.00');
    }

    public function test_opening_an_exchange_for_an_unaffordable_tier_is_refused_before_any_ad(): void
    {
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        config(['minimax.spin_prob_1' => '100.00', 'minimax.spin_prob_2' => '0.00', 'minimax.spin_prob_3' => '0.00', 'minimax.spin_prob_4' => '0.00', 'minimax.spin_prob_5' => '0.00']);
        $token = $this->openClaim(['purpose' => 'spin']);
        $this->sendCallback($this->signedCallback($token, 'spin-only'));
        $this->assertSame(1, SpinAttempt::sum('tokens_awarded')); // can't yet afford tier 1 (100 tokens)

        // Opening a claim for a tier the player cannot afford is refused up front.
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'token_exchange', 'exchange_tier' => 1])->assertUnprocessable();
        $this->assertSame(0, TokenExchangeTransaction::count());
        $this->assertSame(0, WalletLedgerEntry::where('type', 'ad_reward')->count());
    }
}
