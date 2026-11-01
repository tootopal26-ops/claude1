<?php

namespace Tests\Feature\Ads;

use App\Domain\Tokens\TokenExchangeService;
use App\Livewire\Admin\Ads\SpinRewards;
use App\Models\RewardedClaim;
use App\Models\SpinAttempt;
use App\Models\StaffUser;
use App\Models\TokenExchangeTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminSpinRewardsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    private function claim(User $user, string $purpose): RewardedClaim
    {
        return RewardedClaim::create([
            'token' => md5($purpose.$user->id.microtime()),
            'user_id' => $user->id,
            'purpose' => $purpose,
            'placement_key' => $purpose,
            'status' => 'verified',
            'expires_at' => now()->addHour(),
            'provider' => 'levelplay',
            'provider_event_id' => 'evt-'.md5($purpose.$user->id.microtime()),
            'verification_method' => 'levelplay_s2s',
            'verified_at' => now(),
        ]);
    }

    private function spinAttempt(User $user, int $number, int $tokens, ?CarbonImmutable $when = null): SpinAttempt
    {
        $attempt = SpinAttempt::create([
            'user_id' => $user->id,
            'rewarded_claim_id' => $this->claim($user, 'spin')->id,
            'winning_number' => $number,
            'tokens_awarded' => $tokens,
            'probabilities_snapshot' => ['1' => '80.00', '2' => '10.00', '3' => '5.00', '4' => '3.00', '5' => '2.00'],
        ]);

        if ($when) {
            $attempt->forceFill(['created_at' => $when])->save();
        }

        return $attempt;
    }

    private function exchange(User $user, int $tier, int $tokens, string $coins, string $status = 'credited', ?CarbonImmutable $when = null): TokenExchangeTransaction
    {
        $tx = TokenExchangeTransaction::create([
            'user_id' => $user->id,
            'rewarded_claim_id' => $this->claim($user, 'token_exchange')->id,
            'tier_index' => $tier,
            'tokens_required' => $tokens,
            'coins_received' => $coins,
            'bonus_percent' => '0.00',
            'status' => $status,
        ]);

        if ($when) {
            $tx->forceFill(['created_at' => $when])->save();
        }

        return $tx;
    }

    public function test_the_page_loads_the_current_settings_and_the_fixed_tiers(): void
    {
        Livewire::actingAs($this->admin(), 'staff')->test(SpinRewards::class)
            ->assertSet('values.spin_enabled', false)
            ->assertSet('values.spin_prob_1', '80.00')
            ->assertSee('50')
            ->assertSee('20,000')
            ->assertSee('+5%');
    }

    public function test_saving_odds_that_do_not_sum_to_100_is_refused_and_a_valid_set_saves(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'staff')->test(SpinRewards::class)
            ->set('values.spin_prob_1', '50.00')
            ->call('save')
            ->assertHasErrors('values.spin_prob_5');

        $this->assertNotSame('50.00', (string) config('minimax.spin_prob_1'));

        Livewire::actingAs($admin, 'staff')->test(SpinRewards::class)
            ->set('values.spin_enabled', true)
            ->set('values.spin_prob_1', '70.00')
            ->set('values.spin_prob_2', '15.00')
            ->set('values.spin_prob_3', '8.00')
            ->set('values.spin_prob_4', '4.00')
            ->set('values.spin_prob_5', '3.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) config('minimax.spin_enabled'));
        $this->assertSame('70.00', (string) config('minimax.spin_prob_1'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_settings.updated', 'actor_id' => $admin->id]);
    }

    public function test_stats_reflect_real_spin_and_exchange_activity_for_the_configured_window(): void
    {
        $tz = (string) config('minimax.daily_reward_timezone', 'Asia/Kolkata');
        $player = User::factory()->create();

        // Today, inside the window.
        $this->spinAttempt($player, 1, 1);
        $this->spinAttempt($player, 2, 2);

        // Three days ago, still inside the 7-day window.
        $this->spinAttempt($player, 1, 1, CarbonImmutable::now($tz)->subDays(3));

        // Nine days ago, outside the window.
        $this->spinAttempt($player, 5, 5, CarbonImmutable::now($tz)->subDays(9));

        $this->exchange($player, 0, 50, '1.00', 'credited');
        $this->exchange($player, 1, 100, '2.00', 'failed');

        $stats = Livewire::actingAs($this->admin(), 'staff')->test(SpinRewards::class)->viewData('stats');

        $this->assertSame(2, $stats['spins_today']);
        $this->assertSame(3, $stats['tokens_awarded_today']);
        $this->assertSame(3, $stats['spins_week']);
        $this->assertSame(4, $stats['tokens_awarded_week']);
        $this->assertSame(1, $stats['exchanges_week']);
        $this->assertSame('1.00', $stats['coins_exchanged_week']);
        $this->assertSame(50, $stats['tokens_exchanged_week']);
    }

    public function test_the_exchange_tier_table_matches_the_fixed_spec_exactly(): void
    {
        $tiers = app(TokenExchangeService::class)->tiers();

        $this->assertCount(10, $tiers);
        $this->assertSame(50, $tiers[0]['tokens']);
        $this->assertSame('1.00', $tiers[0]['coins']);
        $this->assertSame('0.00', $tiers[0]['bonus_percent']);
        $this->assertSame(20000, $tiers[9]['tokens']);
        $this->assertSame('420.00', $tiers[9]['coins']);
        $this->assertSame('5.00', $tiers[9]['bonus_percent']);

        Livewire::actingAs($this->admin(), 'staff')->test(SpinRewards::class)
            ->assertSee('1.00')->assertSee('420.00');
    }
}
