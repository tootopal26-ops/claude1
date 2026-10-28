<?php

namespace Tests\Feature\Ads;

use App\Domain\Ads\AdsAnalytics;
use App\Domain\Ads\AdsConfig;
use App\Domain\Ads\AdsEarningService;
use App\Livewire\Admin\Ads\AdUnits;
use App\Livewire\Admin\Ads\EarningSettings;
use App\Livewire\Admin\Ads\Placements;
use App\Livewire\Admin\Ads\RevenueReports;
use App\Livewire\Admin\Ads\Settlements;
use App\Livewire\Admin\RedeemCodes;
use App\Livewire\Admin\TournamentCreate;
use App\Models\AdEvent;
use App\Models\AdPlacement;
use App\Models\AdRevenueReport;
use App\Models\AdUnit;
use App\Models\Game;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\RedeemCampaign;
use App\Models\RewardedClaim;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAdsPagesTest extends TestCase
{
    use ConfiguresAds;
    use RefreshDatabase;

    private const array READ_ONLY_PAGES = ['', '/revenue', '/settlements', '/users', '/verification', '/audit'];

    private const array MANAGE_PAGES = ['/earning', '/units', '/placements', '/daily-rewards', '/campaigns', '/tickets'];

    private function staffWith(array $permissions): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'p-'.uniqid()]);
        foreach ($permissions as $key) {
            $preset->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']));
        }

        return StaffUser::factory()->create(['is_super_admin' => false, 'permission_preset_id' => $preset->id]);
    }

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    public function test_read_only_staff_see_analytics_but_not_configuration(): void
    {
        $viewer = $this->staffWith(['ads.view']);

        foreach (self::READ_ONLY_PAGES as $page) {
            $this->actingAs($viewer, 'staff')->get('/staff/ads'.$page)->assertOk();
        }
        foreach (self::MANAGE_PAGES as $page) {
            $this->actingAs($viewer, 'staff')->get('/staff/ads'.$page)->assertForbidden();
        }

        $this->actingAs($this->staffWith([]), 'staff')->get('/staff/ads')->assertForbidden();
    }

    public function test_read_only_staff_cannot_change_revenue_or_settlements_even_by_calling_actions(): void
    {
        $viewer = $this->staffWith(['ads.view']);

        Livewire::actingAs($viewer, 'staff')->test(RevenueReports::class)
            ->set('revenue', '10')->set('source', 'x')
            ->call('add')
            ->assertForbidden();

        Livewire::actingAs($viewer, 'staff')->test(Settlements::class)
            ->call('openLastWeek')
            ->assertForbidden();

        $this->assertSame(0, AdRevenueReport::count());
    }

    public function test_the_rewarded_ads_switch_saves_on_the_server_and_survives_a_reload(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'staff')->test(EarningSettings::class)
            ->assertSet('rewardedEnabled', false)
            ->call('setRewarded', true)
            ->assertSet('rewardedEnabled', true)
            ->assertSee('Enabled');

        $this->assertDatabaseHas('platform_settings', ['key' => 'ads_rewarded_enabled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_settings.updated', 'actor_id' => $admin->id]);

        // A fresh page load reads the saved state.
        Livewire::actingAs($admin, 'staff')->test(EarningSettings::class)->assertSet('rewardedEnabled', true);

        Livewire::actingAs($admin, 'staff')->test(EarningSettings::class)
            ->call('setRewarded', false)
            ->assertSet('rewardedEnabled', false);
        $this->assertFalse((bool) config('minimax.ads_rewarded_enabled'));
    }

    public function test_revenue_shares_must_total_100_on_the_server(): void
    {
        Livewire::actingAs($this->admin(), 'staff')->test(EarningSettings::class)
            ->set('values.ads_earning_business_share', '70')
            ->set('values.ads_earning_user_share', '40')
            ->call('save')
            ->assertHasErrors('values.ads_earning_user_share');

        $this->assertSame('60.00', number_format((float) config('minimax.ads_earning_business_share'), 2));

        Livewire::actingAs($this->admin(), 'staff')->test(EarningSettings::class)
            ->set('values.ads_earning_business_share', '55')
            ->set('values.ads_earning_user_share', '45')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('45.00', (string) config('minimax.ads_earning_user_share'));
    }

    public function test_ad_units_are_validated_unique_and_audited(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'staff')->test(AdUnits::class)
            ->set('name', 'Bad')->set('levelplayAdUnitId', 'javascript:alert(1)')
            ->call('save')
            ->assertHasErrors('levelplayAdUnitId');

        Livewire::actingAs($admin, 'staff')->test(AdUnits::class)
            ->set('name', 'Rewarded prod')->set('format', 'rewarded')->set('levelplayAdUnitId', 'abc123xyz')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($admin, 'staff')->test(AdUnits::class)
            ->set('name', 'Duplicate')->set('format', 'rewarded')->set('levelplayAdUnitId', 'abc123xyz')
            ->call('save')
            ->assertHasErrors('levelplayAdUnitId');

        $this->assertSame(1, AdUnit::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ads.ad_unit.created', 'actor_id' => $admin->id]);
    }

    public function test_placements_need_a_matching_active_unit_and_unsupported_ones_stay_off(): void
    {
        $admin = $this->admin();
        config(['minimax.ads_master_enabled' => true, 'minimax.ads_rewarded_enabled' => true, 'minimax.levelplay_app_key' => 'appkey']);
        $banner = AdUnit::create(['name' => 'Banner', 'format' => 'banner', 'environment' => 'production', 'levelplay_ad_unit_id' => 'bn1']);
        $rewarded = AdUnit::create(['name' => 'Rewarded', 'format' => 'rewarded', 'environment' => 'production', 'levelplay_ad_unit_id' => 'rw1']);
        $appOpen = AdPlacement::where('key', 'app_open')->firstOrFail();
        $ticket = AdPlacement::where('key', 'tournament_ticket')->firstOrFail();

        Livewire::actingAs($admin, 'staff')->test(Placements::class)
            ->set("rows.{$appOpen->id}.is_enabled", true)
            ->call('save', $appOpen->id)
            ->assertHasErrors("rows.{$appOpen->id}.is_enabled");

        Livewire::actingAs($admin, 'staff')->test(Placements::class)
            ->set("rows.{$ticket->id}.is_enabled", true)
            ->set("rows.{$ticket->id}.ad_unit_id", (string) $banner->id)
            ->call('save', $ticket->id)
            ->assertHasErrors("rows.{$ticket->id}.ad_unit_id");

        $this->assertFalse(app(AdsConfig::class)->isPlacementActive('tournament_ticket'));

        Livewire::actingAs($admin, 'staff')->test(Placements::class)
            ->set("rows.{$ticket->id}.is_enabled", true)
            ->set("rows.{$ticket->id}.ad_unit_id", (string) $rewarded->id)
            ->call('save', $ticket->id)
            ->assertHasNoErrors();

        $this->assertTrue((new AdsConfig)->isPlacementActive('tournament_ticket'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ads.placement.updated']);

        // The global switch overrides the placement.
        config(['minimax.ads_master_enabled' => false]);
        $this->assertFalse((new AdsConfig)->isPlacementActive('tournament_ticket'));
    }

    public function test_feed_placements_accept_an_insert_interval(): void
    {
        $admin = $this->admin();
        $native = AdUnit::create(['name' => 'Native', 'format' => 'native', 'environment' => 'production', 'levelplay_ad_unit_id' => 'nt1']);
        $feed = AdPlacement::where('key', 'leaderboard_feed')->firstOrFail();

        Livewire::actingAs($admin, 'staff')->test(Placements::class)
            ->set("rows.{$feed->id}.is_enabled", true)
            ->set("rows.{$feed->id}.ad_unit_id", (string) $native->id)
            ->set("rows.{$feed->id}.insert_every", '5')
            ->call('save', $feed->id)
            ->assertHasNoErrors();

        $this->assertSame(5, $feed->fresh()->insert_every);
    }

    public function test_revenue_reports_are_finalized_once_without_overlap_and_disputes_exclude_them(): void
    {
        $admin = $this->admin();
        $component = Livewire::actingAs($admin, 'staff')->test(RevenueReports::class)
            ->set('periodStart', now()->subDays(3)->toDateString())
            ->set('periodEnd', now()->subDays(2)->toDateString())
            ->set('placementKey', 'ads_earning')
            ->set('revenue', '12.5')
            ->set('source', 'LevelPlay dashboard')
            ->call('add')
            ->assertHasNoErrors();

        $first = AdRevenueReport::firstOrFail();
        $this->assertSame('estimated', $first->status);
        $component->call('finalize', $first->id)->assertHasNoErrors();
        $this->assertSame('finalized', $first->fresh()->status);

        // A second figure for overlapping days can't also be finalized.
        $component->set('periodStart', now()->subDays(2)->toDateString())->set('periodEnd', now()->subDays(2)->toDateString())
            ->set('revenue', '3')->set('source', 'Duplicate')->call('add');
        $second = AdRevenueReport::latest('id')->firstOrFail();
        $component->call('finalize', $second->id)->assertHasErrors('reports');

        $component->call('dispute', $first->id);
        $this->assertSame('disputed', $first->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ads.revenue_report.disputed']);

        // Future days are refused.
        $component->set('periodStart', now()->addDays(2)->toDateString())->set('periodEnd', now()->addDays(2)->toDateString())
            ->set('revenue', '1')->set('source', 'x')->call('add')->assertHasErrors('periodStart');
    }

    public function test_a_settlement_runs_end_to_end_from_the_admin_page(): void
    {
        $this->enableAds();
        config(['minimax.ads_earning_enabled' => true]);
        $admin = $this->admin();
        $player = User::factory()->create();
        $this->deposit($player, '100');

        [$start, $end] = app(AdsEarningService::class)->lastCompletedPeriod();
        RewardedClaim::create([
            'token' => str_repeat('a', 32), 'user_id' => $player->id, 'purpose' => 'ads_earning', 'placement_key' => 'ads_earning',
            'status' => 'verified', 'expires_at' => $start->addHour(), 'provider' => 'levelplay', 'provider_event_id' => 'evt-s1',
            'verification_method' => 'levelplay_s2s', 'counts_for_earning' => true, 'verified_at' => $start->addHour(),
        ]);
        $day = $start->setTimezone((string) config('minimax.ads_earning_timezone'))->addHours(2)->toDateString();
        AdRevenueReport::create(['period_start' => $day, 'period_end' => $day, 'placement_key' => 'ads_earning', 'revenue_usd' => '1.000000', 'status' => 'finalized', 'source' => 'test']);

        $page = Livewire::actingAs($admin, 'staff')->test(Settlements::class)->call('openLastWeek')->assertHasNoErrors();
        $page->set('rate', '80')->set('rateSource', 'Test rate')->call('calculate')->assertHasNoErrors();
        $page->call('credit')->assertHasNoErrors(); // queue is sync in tests

        // $1.00 × 40% = $0.40 × 80 = ₹32 × coins per ₹1 → one player holds every unit.
        $expected = number_format(32 * (float) config('minimax.ads_earning_coins_per_inr'), 2, '.', '');
        $this->assertSame($expected, (string) WalletLedgerEntry::where('user_id', $player->id)->where('type', 'ads_earning_reward')->value('amount'));

        // Crediting again pays nothing more.
        $page->call('credit');
        $this->assertSame(1, WalletLedgerEntry::where('type', 'ads_earning_reward')->count());
    }

    public function test_analytics_exclude_test_events_and_simulated_completions(): void
    {
        $player = User::factory()->create();
        $now = CarbonImmutable::now();
        foreach ([false, false, true] as $isTest) {
            AdEvent::create(['user_id' => $player->id, 'placement_key' => 'ads_earning', 'event' => 'impression', 'ad_network' => 'admob', 'revenue_usd' => '0.01', 'is_test' => $isTest, 'occurred_at' => $now]);
        }
        AdEvent::create(['user_id' => $player->id, 'placement_key' => 'ads_earning', 'event' => 'requested', 'is_test' => false, 'occurred_at' => $now]);
        AdEvent::create(['user_id' => $player->id, 'placement_key' => 'ads_earning', 'event' => 'no_fill', 'is_test' => false, 'occurred_at' => $now]);
        foreach (['levelplay_s2s' => 'evt-a', 'simulated' => 'sim-b'] as $method => $event) {
            RewardedClaim::create([
                'token' => md5($event), 'user_id' => $player->id, 'purpose' => 'ads_earning', 'placement_key' => 'ads_earning',
                'status' => 'verified', 'expires_at' => $now->addHour(), 'provider' => $method === 'simulated' ? 'simulated' : 'levelplay',
                'provider_event_id' => $event, 'verification_method' => $method, 'verified_at' => $now,
            ]);
        }

        $analytics = app(AdsAnalytics::class);
        [$start, $end] = $analytics->range('today');
        $stats = $analytics->overview($start, $end);

        $this->assertSame(2, $stats['impressions']);
        $this->assertSame(1, $stats['test_events']);
        $this->assertSame(1, $stats['rewarded_completed']);
        $this->assertSame(1, $stats['simulated_completed']);
        $this->assertSame(1, $stats['failed_requests']);
        $this->assertSame('0.020000', $stats['estimate']['today']);
        $this->assertSame(2, collect($analytics->daily($start, $end))->sum('impressions'));
        $this->assertSame('0.000000', $stats['provider_revenue_finalized']);
    }

    public function test_events_recorded_in_server_test_mode_are_test_only_whatever_the_app_says(): void
    {
        config(['minimax.ads_test_mode' => true]);
        $player = User::factory()->create();

        $this->actingAs($player, 'sanctum')->postJson('/api/v1/ads/events', ['events' => [
            ['placement' => 'home_hero', 'event' => 'impression', 'revenue_usd' => 0.5, 'is_test' => false],
        ]])->assertCreated();

        $this->assertTrue((bool) AdEvent::firstOrFail()->is_test);
    }

    public function test_tournament_creation_separates_free_with_tickets_from_paid(): void
    {
        $admin = $this->admin();
        $game = Game::factory()->create();
        $base = fn () => Livewire::actingAs($admin, 'staff')->test(TournamentCreate::class)
            ->set('game_id', $game->id)->set('title', 'T')->set('total_slots', 10)
            ->set('scheduled_start_at', now()->addDay()->format('Y-m-d\TH:i'));

        $base()->assertSet('entry_mode', 'free')->assertSet('required_ad_tickets', 1)
            ->set('required_ad_tickets', 3)->call('save')->assertHasNoErrors();
        $this->assertSame(3, Tournament::latest('id')->first()->required_ad_tickets);

        $base()->set('entry_mode', 'paid')->assertSet('required_ad_tickets', 0)->set('entry_fee', 0)->call('save')->assertHasErrors('entry_fee');
        $base()->set('entry_mode', 'paid')->set('entry_fee', 20)->call('save')->assertHasNoErrors();
        $paid = Tournament::latest('id')->first();
        $this->assertSame('20.00', (string) $paid->entry_fee);
        $this->assertSame(0, $paid->required_ad_tickets);

        $base()->set('required_ad_tickets', 99)->call('save')->assertHasErrors('required_ad_tickets');
    }

    public function test_a_redeem_code_can_be_generated_that_needs_a_rewarded_ad(): void
    {
        Livewire::actingAs($this->admin(), 'staff')->test(RedeemCodes::class)
            ->set('name', 'Ad code')->set('capacity', '10')->set('reward', '5')->set('requiresAd', true)
            ->call('generate')
            ->assertHasNoErrors();

        $this->assertTrue(RedeemCampaign::firstOrFail()->requires_rewarded_ad);
        $this->assertDatabaseHas('audit_logs', ['action' => 'redeem_campaign.created']);
    }
}
