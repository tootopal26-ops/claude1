<?php

namespace Tests\Feature\Ads;

use App\Domain\Ads\AdsEarningService;
use App\Domain\Ads\AdSettlementService;
use App\Domain\Settings\PlatformSettingsService;
use App\Domain\Wallet\WalletService;
use App\Jobs\CreditAdSettlementJob;
use App\Models\AdRevenueReport;
use App\Models\AdSettlementAllocation;
use App\Models\AdSettlementBatch;
use App\Models\RewardedClaim;
use App\Models\StaffUser;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdsEarningSettlementTest extends TestCase
{
    use ConfiguresAds;
    use RefreshDatabase;

    private StaffUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAds();
        config([
            'minimax.ads_earning_enabled' => true,
            'minimax.ads_earning_daily_view_limit' => 5,
            'minimax.ads_earning_timezone' => 'Asia/Kolkata',
            'minimax.ads_earning_settlement_day' => 1,
            'minimax.ads_earning_settlement_time' => '06:00',
        ]);
        $this->admin = StaffUser::factory()->create(['is_super_admin' => true]);
    }

    private function eligiblePlayer(): User
    {
        $player = User::factory()->create();
        $this->deposit($player, '50.00');

        return $player;
    }

    private function watchEarningAd(User $player, string $eventId): string
    {
        Sanctum::actingAs($player);
        $token = $this->postJson('/api/v1/ads/claims', ['purpose' => 'ads_earning'])->assertCreated()->json('data.token');
        $this->sendCallback($this->signedCallback($token, $eventId))->assertOk();

        return $token;
    }

    public function test_switching_on_needs_eligibility_and_is_saved_on_the_server(): void
    {
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $this->postJson('/api/v1/ads-earning/preference', ['enabled' => true])->assertUnprocessable();
        $this->deposit($player, '50.00');
        $this->postJson('/api/v1/ads-earning/preference', ['enabled' => true])->assertOk()->assertJsonPath('data.opted_in', true);
        $this->assertDatabaseHas('ads_earning_preferences', ['user_id' => $player->id, 'enabled' => true]);

        $this->postJson('/api/v1/ads-earning/preference', ['enabled' => false])->assertOk()->assertJsonPath('data.opted_in', false);
    }

    public function test_master_off_or_player_off_stops_new_earning_ads(): void
    {
        $player = $this->eligiblePlayer();
        Sanctum::actingAs($player);

        $this->postJson('/api/v1/ads/claims', ['purpose' => 'ads_earning'])->assertUnprocessable(); // not opted in
        $this->postJson('/api/v1/ads-earning/preference', ['enabled' => true])->assertOk();
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'ads_earning'])->assertCreated();

        config(['minimax.ads_earning_enabled' => false]);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'ads_earning'])->assertUnprocessable();

        config(['minimax.ads_earning_enabled' => true, 'minimax.ads_earning_paused' => true]);
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'ads_earning'])->assertUnprocessable();
    }

    public function test_units_are_decided_at_verification_and_capped_per_day(): void
    {
        config(['minimax.ads_earning_daily_view_limit' => 2]);
        $player = $this->eligiblePlayer();
        Sanctum::actingAs($player);
        $this->postJson('/api/v1/ads-earning/preference', ['enabled' => true])->assertOk();

        $first = $this->watchEarningAd($player, 'e1');
        $second = $this->watchEarningAd($player, 'e2');
        $this->postJson('/api/v1/ads/claims', ['purpose' => 'ads_earning'])->assertUnprocessable();

        // A claim opened earlier still completes, but past the cap it does not count.
        $extra = RewardedClaim::create(['token' => str_repeat('c', 32), 'user_id' => $player->id, 'purpose' => 'ads_earning', 'placement_key' => 'ads_earning', 'status' => 'pending', 'expires_at' => now()->addMinutes(10)]);
        $this->sendCallback($this->signedCallback($extra->token, 'e3'))->assertOk();

        $this->assertTrue(RewardedClaim::where('token', $first)->value('counts_for_earning'));
        $this->assertTrue(RewardedClaim::where('token', $second)->value('counts_for_earning'));
        $this->assertFalse($extra->fresh()->counts_for_earning);

        // Switching off later never removes units already earned.
        $this->postJson('/api/v1/ads-earning/preference', ['enabled' => false])->assertOk();
        $this->assertSame(2, app(AdsEarningService::class)->unitsToday($player));
        $this->assertSame(0, WalletLedgerEntry::where('type', 'ads_earning_reward')->count());
    }

    public function test_the_shares_must_add_up_to_100_on_the_server(): void
    {
        $this->expectException(ValidationException::class);
        app(PlatformSettingsService::class)->setMany(['ads_earning_business_share' => '60', 'ads_earning_user_share' => '45'], $this->admin);
    }

    public function test_a_settlement_splits_finalized_revenue_by_units_and_credits_exactly_once(): void
    {
        [$start, $end] = $this->settlementWeek();
        $alice = $this->eligiblePlayer();
        $bob = $this->eligiblePlayer();
        foreach ([$alice, $bob] as $player) {
            Sanctum::actingAs($player);
            $this->postJson('/api/v1/ads-earning/preference', ['enabled' => true])->assertOk();
        }

        $this->travelTo($start->addDay());
        $this->watchEarningAd($alice, 'a1');
        $this->watchEarningAd($alice, 'a2');
        $this->watchEarningAd($bob, 'b1');
        $this->travelTo($end->addHour());

        $settlements = app(AdSettlementService::class);
        $batch = $settlements->openBatch($start, $end, $this->admin);

        // Estimated revenue is not enough to settle.
        AdRevenueReport::create(['period_start' => $start->setTimezone('Asia/Kolkata')->toDateString(), 'period_end' => $end->setTimezone('Asia/Kolkata')->subDay()->toDateString(), 'placement_key' => 'ads_earning', 'revenue_usd' => '1.000000', 'status' => 'estimated', 'source' => 'LevelPlay dashboard']);
        try {
            $settlements->calculate($batch, '80.00', 'RBI reference rate', $this->admin);
            $this->fail('An estimate must not be settled.');
        } catch (ValidationException) {
        }

        AdRevenueReport::query()->update(['status' => 'finalized']);
        $batch = $settlements->calculate($batch, '80.00', 'RBI reference rate', $this->admin);

        // $1.00 → business $0.60, players $0.40 → ₹32.00 → 32.00 coins at 1 coin per ₹1.
        $this->assertSame('0.600000', (string) $batch->business_share_usd);
        $this->assertSame('0.400000', (string) $batch->user_pool_usd);
        $this->assertSame('32.0000', (string) $batch->user_pool_inr);
        $this->assertSame(3, $batch->total_units);
        $this->assertSame('21.33', (string) AdSettlementAllocation::where('user_id', $alice->id)->value('coins'));
        $this->assertSame('10.66', (string) AdSettlementAllocation::where('user_id', $bob->id)->value('coins'));
        $this->assertSame('0.0100', (string) $batch->remainder_coins);

        // Calculated but not credited: shown as pending, nothing in the ledger.
        Sanctum::actingAs($alice);
        $this->getJson('/api/v1/ads-earning')->assertJsonPath('data.pending_coins', '21.33')->assertJsonPath('data.credited_coins', '0.00');
        $this->getJson('/api/v1/ads-earning/history')->assertJsonPath('data.0.status', 'pending');

        CreditAdSettlementJob::dispatchSync($batch->id, $this->admin->id);
        CreditAdSettlementJob::dispatchSync($batch->id, $this->admin->id); // a duplicate job: no-op

        // Worst case: a crash after the ledger credits but before the allocations were marked.
        // Re-running must not pay again — the per-allocation idempotency key returns the first entry.
        $batch->fresh()->update(['status' => 'failed']);
        AdSettlementAllocation::query()->update(['status' => 'failed']);
        $settlements->credit($batch->fresh(), $this->admin);

        $this->assertSame(2, WalletLedgerEntry::where('type', 'ads_earning_reward')->count());
        $this->assertSame('21.33', (string) WalletLedgerEntry::where('user_id', $alice->id)->where('type', 'ads_earning_reward')->value('amount'));
        $this->assertSame('deposit', WalletLedgerEntry::where('type', 'ads_earning_reward')->value('balance'));
        $this->assertSame('completed', $batch->fresh()->status);
        $this->getJson('/api/v1/ads-earning/history')->assertJsonPath('data.0.status', 'credited');
        $this->getJson('/api/v1/ads-earning')->assertJsonPath('data.credited_coins', '21.33')->assertJsonPath('data.pending_coins', '0.00');

        // A credited settlement can't be recalculated.
        $this->expectException(ValidationException::class);
        $settlements->calculate($batch->fresh(), '81.00', 'x', $this->admin);
    }

    public function test_a_failed_credit_is_kept_and_retried_without_paying_twice(): void
    {
        [$start, $end] = $this->settlementWeek();
        $player = $this->eligiblePlayer();
        Sanctum::actingAs($player);
        $this->postJson('/api/v1/ads-earning/preference', ['enabled' => true])->assertOk();
        $this->travelTo($start->addDay());
        $this->watchEarningAd($player, 'x1');
        $this->travelTo($end->addHour());

        $settlements = app(AdSettlementService::class);
        $batch = $settlements->openBatch($start, $end, $this->admin);
        AdRevenueReport::create(['period_start' => $start->setTimezone('Asia/Kolkata')->toDateString(), 'period_end' => $end->setTimezone('Asia/Kolkata')->subDay()->toDateString(), 'placement_key' => 'ads_earning', 'revenue_usd' => '0.500000', 'status' => 'finalized', 'source' => 'LevelPlay']);
        $batch = $settlements->calculate($batch, '80.00', 'RBI', $this->admin);

        // First credit fails (the wallet service throws once).
        $real = app(WalletService::class);
        $this->app->instance(WalletService::class, new class($real) extends WalletService
        {
            public function __construct(private WalletService $inner) {}

            public function creditReward(...$args): WalletLedgerEntry
            {
                throw new \RuntimeException('Database briefly unavailable');
            }
        });
        $failed = app(AdSettlementService::class)->credit($batch, $this->admin);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('failed', AdSettlementAllocation::sole()->status);

        $this->app->instance(WalletService::class, $real);
        $done = app(AdSettlementService::class)->credit($failed, $this->admin);
        $this->assertSame('completed', $done->status);
        $this->assertSame(1, WalletLedgerEntry::where('type', 'ads_earning_reward')->count());
        $this->assertSame(2, AdSettlementAllocation::sole()->attempts);
    }

    public function test_a_week_cannot_be_settled_before_it_ends_and_the_period_follows_the_admin_cutoff(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Asia/Kolkata')); // a Wednesday
        [$start, $end] = app(AdsEarningService::class)->periodContaining();
        $this->assertSame('2026-10-19 06:00', $start->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-26 06:00', $end->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));

        $this->expectException(ValidationException::class);
        app(AdSettlementService::class)->openBatch($start, $end, $this->admin);
    }

    public function test_one_batch_per_period(): void
    {
        [$start, $end] = $this->settlementWeek();
        $this->travelTo($end->addHour());

        $first = app(AdSettlementService::class)->openBatch($start, $end, $this->admin);
        $second = app(AdSettlementService::class)->openBatch($start, $end, $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AdSettlementBatch::count());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function settlementWeek(): array
    {
        $start = CarbonImmutable::parse('2026-10-12 06:00', 'Asia/Kolkata')->utc();

        return [$start, $start->addWeek()];
    }
}
