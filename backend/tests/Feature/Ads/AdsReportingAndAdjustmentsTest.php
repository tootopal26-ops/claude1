<?php

namespace Tests\Feature\Ads;

use App\Domain\Ads\AdsAnalytics;
use App\Domain\Ads\AdsEarningService;
use App\Domain\Ads\AdSettlementService;
use App\Livewire\Admin\Ads\Settlements;
use App\Models\AdEvent;
use App\Models\AdPlacement;
use App\Models\AdRevenueReport;
use App\Models\AdsEarningPreference;
use App\Models\AdSettlementAllocation;
use App\Models\AdSettlementBatch;
use App\Models\AdUnit;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\RewardedClaim;
use App\Models\StaffUser;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class AdsReportingAndAdjustmentsTest extends TestCase
{
    use ConfiguresAds;
    use RefreshDatabase;

    private StaffUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = StaffUser::factory()->superAdmin()->create();
    }

    /**
     * One player with one verified unit in last week, $1 finalized for Ads Earning and $5 for
     * another placement, settled and credited.
     *
     * @return array{0: AdSettlementBatch, 1: User}
     */
    private function completedSettlement(): array
    {
        $this->enableAds();
        config(['minimax.ads_earning_enabled' => true, 'minimax.ads_earning_coins_per_inr' => '1.00']);
        $player = User::factory()->create();
        $this->deposit($player, '100');

        [$start] = app(AdsEarningService::class)->lastCompletedPeriod();
        RewardedClaim::create([
            'token' => str_repeat('d', 32), 'user_id' => $player->id, 'purpose' => 'ads_earning', 'placement_key' => 'ads_earning',
            'status' => 'verified', 'expires_at' => $start->addHour(), 'provider' => 'levelplay', 'provider_event_id' => 'evt-adj',
            'verification_method' => 'levelplay_s2s', 'counts_for_earning' => true, 'verified_at' => $start->addHour(),
        ]);
        $day = $start->setTimezone('Asia/Kolkata')->addHours(2)->toDateString();
        AdRevenueReport::create(['period_start' => $day, 'period_end' => $day, 'placement_key' => 'ads_earning', 'revenue_usd' => '1.000000', 'status' => 'finalized', 'source' => 'LevelPlay report A']);
        AdRevenueReport::create(['period_start' => $day, 'period_end' => $day, 'placement_key' => 'home_hero', 'revenue_usd' => '5.000000', 'status' => 'finalized', 'source' => 'Banner revenue']);

        $settlements = app(AdSettlementService::class);
        $batch = $settlements->calculate($settlements->openBatch(), '80.00', 'Test rate', $this->admin);
        $settlements->credit($batch, $this->admin);

        return [$batch->fresh(), $player];
    }

    public function test_a_settlement_records_the_reports_it_used_and_ignores_other_placements_revenue(): void
    {
        [$batch, $player] = $this->completedSettlement();

        $this->assertSame('1.000000', (string) $batch->eligible_revenue_usd);
        $this->assertCount(1, $batch->revenue_sources);
        $this->assertSame('LevelPlay report A', $batch->revenue_sources[0]['source']);
        // $1 × 40% × 80 × 1 coin per INR 1 = 32 coins.
        $this->assertSame('32.00', (string) WalletLedgerEntry::where('user_id', $player->id)->where('type', 'ads_earning_reward')->value('amount'));
    }

    public function test_a_correction_is_its_own_audited_ledger_entry_and_shows_as_adjusted_with_its_reason(): void
    {
        [$batch, $player] = $this->completedSettlement();
        $allocation = AdSettlementAllocation::where('ad_settlement_batch_id', $batch->id)->firstOrFail();
        $settlements = app(AdSettlementService::class);

        $adjustment = $settlements->adjust($allocation, '-2.50', 'Provider revised the week down', $this->admin);

        $entry = WalletLedgerEntry::findOrFail($adjustment->wallet_ledger_entry_id);
        $this->assertSame('-2.50', (string) $entry->amount);
        $this->assertSame('admin_adjustment', $entry->type);
        $this->assertSame('deposit', $entry->balance);
        $this->assertSame('credited', $allocation->fresh()->status);
        $this->assertSame('32.00', (string) $allocation->fresh()->coins);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ads.settlement.adjusted', 'actor_id' => $this->admin->id]);

        Sanctum::actingAs($player);
        $this->getJson('/api/v1/ads-earning/history')
            ->assertJsonPath('data.0.status', 'adjusted')
            ->assertJsonPath('data.0.adjustments.0.coins', '-2.50')
            ->assertJsonPath('data.0.adjustments.0.reason', 'Provider revised the week down');
        $this->getJson('/api/v1/ads-earning')->assertJsonPath('data.credited_coins', '29.50');
    }

    public function test_corrections_need_a_reason_a_completed_batch_and_cannot_overdraw(): void
    {
        [$batch] = $this->completedSettlement();
        $allocation = AdSettlementAllocation::where('ad_settlement_batch_id', $batch->id)->firstOrFail();
        $settlements = app(AdSettlementService::class);

        foreach ([['1', 'no'], ['0', 'A long enough reason'], ['-999999', 'A long enough reason'], ['abc', 'A long enough reason']] as [$coins, $reason]) {
            try {
                $settlements->adjust($allocation, $coins, $reason, $this->admin);
                $this->fail("Adjustment {$coins} / {$reason} should be refused");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $batch->update(['status' => 'calculated']);
        $this->expectException(ValidationException::class);
        $settlements->adjust($allocation->fresh(), '1', 'A long enough reason', $this->admin);
    }

    public function test_read_only_staff_cannot_adjust(): void
    {
        [$batch] = $this->completedSettlement();
        $preset = PermissionPreset::create(['name' => 'viewer']);
        $preset->permissions()->attach(Permission::firstOrCreate(['key' => 'ads.view'], ['label' => 'v', 'group' => 'Ads']));
        $viewer = StaffUser::factory()->create(['is_super_admin' => false, 'permission_preset_id' => $preset->id]);
        $allocation = AdSettlementAllocation::where('ad_settlement_batch_id', $batch->id)->firstOrFail();

        Livewire::actingAs($viewer, 'staff')->test(Settlements::class, ['batch' => $batch->id])
            ->call('startAdjust', $allocation->id)
            ->assertForbidden();

        Livewire::actingAs($this->admin, 'staff')->test(Settlements::class, ['batch' => $batch->id])
            ->call('startAdjust', $allocation->id)
            ->set('adjustCoins', '1.25')
            ->set('adjustReason', 'Goodwill top-up after outage')
            ->call('adjust')
            ->assertHasNoErrors();

        $this->assertSame(1, $allocation->adjustments()->count());
    }

    public function test_app_telemetry_keeps_each_ad_units_last_load_and_error(): void
    {
        $unit = AdUnit::create(['name' => 'Banner', 'format' => 'banner', 'environment' => 'production', 'levelplay_ad_unit_id' => 'bn-unit-9']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ads/events', ['events' => [
            ['placement' => 'home_hero', 'event' => 'loaded', 'ad_unit_id' => 'bn-unit-9'],
            ['placement' => 'home_hero', 'event' => 'no_fill', 'ad_unit_id' => 'bn-unit-9', 'error_code' => '509'],
        ]])->assertCreated();

        $unit->refresh();
        $this->assertNotNull($unit->last_loaded_at);
        $this->assertNotNull($unit->last_error_at);
        $this->assertSame('no fill (code 509)', $unit->last_error);
    }

    public function test_the_home_between_sections_placement_exists_and_starts_off(): void
    {
        $placement = AdPlacement::where('key', 'home_between')->firstOrFail();

        $this->assertSame('banner', $placement->format);
        $this->assertFalse($placement->is_enabled);
        $this->assertTrue($placement->is_supported);
    }

    public function test_overview_reports_opt_in_rate_and_sdk_errors(): void
    {
        $players = User::factory()->count(2)->create();
        foreach ($players as $player) {
            $this->deposit($player, '60');
        }
        AdsEarningPreference::create(['user_id' => $players[0]->id, 'enabled' => true]);
        AdEvent::create(['placement_key' => 'sdk', 'event' => 'init_failed', 'is_test' => false, 'occurred_at' => now()]);
        AdEvent::create(['placement_key' => 'home_hero', 'event' => 'display_failed', 'is_test' => true, 'occurred_at' => now()]);

        $analytics = app(AdsAnalytics::class);
        [$start, $end] = $analytics->range('today');
        $stats = $analytics->overview($start, $end);

        $this->assertSame(50.0, $stats['opt_in_rate']);
        $this->assertSame(1, $stats['sdk_errors']);
    }
}
