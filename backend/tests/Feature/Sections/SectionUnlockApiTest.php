<?php

namespace Tests\Feature\Sections;

use App\Domain\Wallet\WalletService;
use App\Models\SectionUnlock;
use App\Models\SectionUnlockPrice;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SectionUnlockApiTest extends TestCase
{
    use RefreshDatabase;

    private function price(int $coins = 250, bool $enabled = true, string $key = 'leaderboard'): SectionUnlockPrice
    {
        return SectionUnlockPrice::factory()->create(['section_key' => $key, 'price_coins' => $coins, 'is_enabled' => $enabled]);
    }

    private function player(string $deposit = '0.00', string $winnings = '0.00'): User
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        if (bccomp($deposit, '0', 2) > 0) {
            $wallet->creditDeposit($user, $deposit, "seed-deposit:{$user->id}");
        }

        if (bccomp($winnings, '0', 2) > 0) {
            $wallet->creditWinnings($user, $winnings, "seed-winnings:{$user->id}");
        }

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_every_section_is_free_until_the_admin_prices_it(): void
    {
        $this->player();

        $this->getJson('/api/v1/sections')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'leaderboard')
            ->assertJsonPath('data.0.paid', false)
            ->assertJsonPath('data.0.price', null)
            ->assertJsonPath('data.0.unlocked', true);

        $this->getJson('/api/v1/leaderboards/daily')->assertOk();

        $this->price(enabled: false);
        $this->getJson('/api/v1/sections')->assertJsonPath('data.0.paid', false)->assertJsonPath('data.0.unlocked', true);
        $this->getJson('/api/v1/leaderboards/daily')->assertOk();
    }

    public function test_a_priced_section_is_listed_with_its_backend_price_and_locked(): void
    {
        $this->price(250);
        $this->player();

        $this->getJson('/api/v1/sections')
            ->assertOk()
            ->assertJsonPath('data.0.paid', true)
            ->assertJsonPath('data.0.price', 250)
            ->assertJsonPath('data.0.unlocked', false)
            ->assertJsonPath('data.0.title', 'Leaderboard');
    }

    public function test_a_locked_section_refuses_its_data_on_the_server_not_just_in_the_app(): void
    {
        $this->price(250);
        $this->player('1000.00');

        $this->getJson('/api/v1/leaderboards/daily')
            ->assertForbidden()
            ->assertJsonPath('error', 'section_locked')
            ->assertJsonPath('section.price', 250);

        // Decorative banners are not part of the locked section.
        $this->getJson('/api/v1/leaderboards/banners')->assertOk();
    }

    public function test_unlocking_charges_the_exact_price_once_records_the_entitlement_and_opens_the_section(): void
    {
        $price = $this->price(250);
        $user = $this->player('1000.00');

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])
            ->assertOk()
            ->assertJsonPath('data.charged', '250.00')
            ->assertJsonPath('data.already_unlocked', false)
            ->assertJsonPath('data.section.unlocked', true)
            ->assertJsonPath('data.balance.deposit_balance', '750.00')
            ->assertJsonPath('data.balance.total_balance', '750.00');

        $entry = WalletLedgerEntry::where('user_id', $user->id)->where('type', 'section_unlock')->sole();
        $this->assertSame('deposit', $entry->balance);
        $this->assertSame('-250.00', $entry->amount);
        $this->assertSame("section_unlock:leaderboard:{$user->id}", $entry->idempotency_key);
        $this->assertSame('Unlocked Leaderboard', $entry->reason);
        $this->assertSame($price->getMorphClass(), $entry->source_type);

        $unlock = SectionUnlock::where('user_id', $user->id)->sole();
        $this->assertSame('leaderboard', $unlock->section_key);
        $this->assertSame(250, $unlock->price_paid);
        $this->assertSame($entry->id, $unlock->wallet_ledger_entry_id);

        $this->getJson('/api/v1/leaderboards/daily')->assertOk();
        $this->getJson('/api/v1/sections')->assertJsonPath('data.0.unlocked', true);
    }

    public function test_the_charge_takes_the_deposit_balance_first_and_then_eligible_winnings(): void
    {
        $this->price(250);
        $user = $this->player('100.00', '200.00');

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])
            ->assertOk()
            ->assertJsonPath('data.balance.deposit_balance', '0.00')
            ->assertJsonPath('data.balance.eligible_winnings_balance', '50.00');

        $this->assertSame(['deposit' => '-100.00', 'winnings' => '-150.00'], WalletLedgerEntry::where('user_id', $user->id)->where('type', 'section_unlock')->pluck('amount', 'balance')->all());
    }

    public function test_an_unaffordable_unlock_is_refused_with_the_price_and_balance_and_changes_nothing(): void
    {
        $this->price(250);
        $user = $this->player('100.00', '50.50');

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_balance')
            ->assertJsonPath('required', '250.00')
            ->assertJsonPath('available', '150.50');

        $this->assertSame(0, SectionUnlock::count());
        $this->assertSame(0, WalletLedgerEntry::where('type', 'section_unlock')->count());
        $wallet = $user->fresh()->wallet;
        $this->assertSame('100.00', $wallet->deposit_balance);
        $this->assertSame('50.50', $wallet->eligible_winnings_balance);
        $this->getJson('/api/v1/leaderboards/daily')->assertForbidden();
    }

    public function test_pressing_unlock_again_never_charges_twice(): void
    {
        $this->price(250);
        $user = $this->player('1000.00');

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertOk()->assertJsonPath('data.already_unlocked', false);
        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])
            ->assertOk()
            ->assertJsonPath('data.already_unlocked', true)
            ->assertJsonPath('data.charged', '0.00')
            ->assertJsonPath('data.balance.deposit_balance', '750.00');

        $this->assertSame(1, SectionUnlock::where('user_id', $user->id)->count());
        $this->assertSame(1, WalletLedgerEntry::where('user_id', $user->id)->where('type', 'section_unlock')->count());
    }

    public function test_the_database_itself_refuses_a_second_entitlement_for_the_same_player_and_section(): void
    {
        $user = User::factory()->create();
        SectionUnlock::factory()->create(['user_id' => $user->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        SectionUnlock::factory()->create(['user_id' => $user->id]);
    }

    public function test_the_client_cannot_choose_the_price(): void
    {
        $this->price(250);
        $user = $this->player('1000.00');

        // Sending a cheaper figure in any field never lowers the charge: a different confirmed price is refused...
        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 1, 'price' => 1, 'amount' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error', 'price_changed')
            ->assertJsonPath('price', 250);

        $this->assertSame(0, SectionUnlock::count());
        $this->assertSame('1000.00', $user->fresh()->wallet->deposit_balance);

        // ...and extra fields are ignored when the confirmed price is right.
        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250, 'price' => 1])->assertOk()->assertJsonPath('data.charged', '250.00');
        $this->assertSame('750.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_a_price_raised_after_the_player_confirmed_is_not_charged_until_they_confirm_again(): void
    {
        $row = $this->price(250);
        $this->player('1000.00');

        $row->update(['price_coins' => 400]);

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])
            ->assertStatus(409)
            ->assertJsonPath('error', 'price_changed')
            ->assertJsonPath('price', 400);

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 400])->assertOk()->assertJsonPath('data.charged', '400.00');
    }

    public function test_a_free_or_unknown_section_cannot_be_bought(): void
    {
        $this->player('1000.00');

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertStatus(409)->assertJsonPath('error', 'not_for_sale');

        $this->price(250, enabled: false);
        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertStatus(409)->assertJsonPath('error', 'not_for_sale');

        $this->postJson('/api/v1/sections/not-a-section/unlock', ['expected_price' => 250])->assertNotFound()->assertJsonPath('error', 'unknown_section');

        $this->assertSame(0, WalletLedgerEntry::where('type', 'section_unlock')->count());
    }

    public function test_the_confirmed_price_is_required(): void
    {
        $this->price(250);
        $this->player('1000.00');

        $this->postJson('/api/v1/sections/leaderboard/unlock')->assertStatus(422)->assertJsonValidationErrors('expected_price');
        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 'cheap'])->assertStatus(422);
        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => -5])->assertStatus(422);
    }

    public function test_a_player_who_already_unlocked_keeps_access_when_the_price_changes_or_is_switched_off(): void
    {
        $row = $this->price(250);
        $this->player('1000.00');
        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertOk();

        $row->update(['price_coins' => 900]);
        $this->getJson('/api/v1/leaderboards/daily')->assertOk();

        $row->update(['is_enabled' => false]);
        $this->getJson('/api/v1/leaderboards/daily')->assertOk();
        $this->getJson('/api/v1/sections')->assertJsonPath('data.0.unlocked', true);

        $row->update(['is_enabled' => true]);
        $this->getJson('/api/v1/leaderboards/daily')->assertOk();
    }

    public function test_a_failed_charge_never_leaves_an_unlock_behind(): void
    {
        $this->price(250);
        $user = $this->player('1000.00');

        $this->mock(WalletService::class, function ($mock) {
            $mock->shouldReceive('debitForPurchase')->andThrow(new \RuntimeException('ledger down'));
            $mock->shouldReceive('getOrCreateWallet')->andReturnUsing(fn (User $u) => Wallet::firstOrCreate(['user_id' => $u->id]));
        });

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertStatus(500);

        $this->assertSame(0, SectionUnlock::count());
        $this->getJson('/api/v1/leaderboards/daily')->assertForbidden();
        $this->assertSame('1000.00', $user->fresh()->wallet->deposit_balance);
    }

    public function test_the_balance_never_goes_negative_when_a_purchase_would_overdraw_it(): void
    {
        $this->price(250);
        $user = $this->player('249.99');

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertStatus(422);

        $this->assertSame('249.99', $user->fresh()->wallet->deposit_balance);
    }

    public function test_banned_players_cannot_buy_and_guests_are_turned_away(): void
    {
        $this->price(250);
        $user = $this->player('1000.00');
        $user->forceFill(['is_banned' => true])->save();

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertForbidden()->assertJsonPath('error', 'ineligible');
        $this->assertSame(0, SectionUnlock::count());
    }

    public function test_guests_cannot_list_or_unlock(): void
    {
        $this->getJson('/api/v1/sections')->assertUnauthorized();
        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertUnauthorized();
        $this->getJson('/api/v1/leaderboards/daily')->assertUnauthorized();
    }

    public function test_unlock_requests_are_rate_limited_per_player(): void
    {
        $this->price(250);
        $user = $this->player();
        RateLimiter::clear('section-unlock-user:'.$user->id);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertStatus(422);
        }

        $this->postJson('/api/v1/sections/leaderboard/unlock', ['expected_price' => 250])->assertStatus(429);
    }

    public function test_pricing_one_section_leaves_every_other_section_free(): void
    {
        config(['minimax.unlockable_sections.arena' => ['title' => 'Arena', 'description' => 'Other.', 'icon' => 'tournaments']]);
        $this->price(250, key: 'leaderboard');
        $this->player('1000.00');

        $sections = collect($this->getJson('/api/v1/sections')->assertOk()->json('data'))->keyBy('key');

        $this->assertTrue($sections['leaderboard']['paid']);
        $this->assertFalse($sections['arena']['paid']);
        $this->assertTrue($sections['arena']['unlocked']);
        $this->postJson('/api/v1/sections/arena/unlock', ['expected_price' => 250])->assertStatus(409)->assertJsonPath('error', 'not_for_sale');
    }
}
