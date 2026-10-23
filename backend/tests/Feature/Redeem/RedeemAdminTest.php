<?php

namespace Tests\Feature\Redeem;

use App\Domain\Redeem\RedeemCodeGenerator;
use App\Livewire\Admin\RedeemCodes;
use App\Livewire\Admin\RedeemHistory;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\RedeemAttempt;
use App\Models\RedeemCampaign;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class RedeemAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    private function generate(array $fields = []): Testable
    {
        $component = Livewire::actingAs($this->admin(), 'staff')->test(RedeemCodes::class);

        foreach (array_merge(['name' => 'Weekend launch', 'capacity' => '500', 'reward' => '100'], $fields) as $key => $value) {
            $component->set($key, $value);
        }

        return $component->call('generate');
    }

    public function test_generating_a_campaign_creates_a_random_code_with_the_default_24_hour_expiry(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->generate()->assertHasNoErrors();

        $campaign = RedeemCampaign::firstOrFail();
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}$/', $campaign->code);
        $this->assertSame('Weekend launch', $campaign->name);
        $this->assertSame(500, $campaign->total_capacity);
        $this->assertSame(100, $campaign->reward_coins);
        $this->assertSame(0, $campaign->redeemed_count);
        $this->assertTrue($campaign->is_active);
        $this->assertTrue($campaign->expires_at->equalTo(now()->addHours(24)), 'default expiry is exactly 24 hours from creation');
        $this->assertSame(50000, $campaign->potentialLiability());
        $this->assertDatabaseHas('audit_logs', ['action' => 'redeem_campaign.created']);
    }

    public function test_the_page_shows_the_generated_code_and_its_exact_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 08:30:00', 'UTC'));

        $this->generate()
            ->assertSee(RedeemCampaign::first()->code)
            ->assertSee('11 Mar 2026, 08:30 UTC')
            ->assertSee('11 Mar, 14:00 IST')
            ->assertSee('50,000');
    }

    public function test_a_custom_duration_is_honoured_and_validated(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->generate(['duration' => 'custom', 'customHours' => '5'])->assertHasNoErrors();
        $this->assertTrue(RedeemCampaign::first()->expires_at->equalTo(now()->addHours(5)));

        foreach (['0', '8761', 'abc', '2.5', ''] as $bad) {
            $this->generate(['duration' => 'custom', 'customHours' => $bad])->assertHasErrors('customHours');
        }

        $this->assertSame(1, RedeemCampaign::count());
    }

    public function test_invalid_campaign_fields_are_rejected(): void
    {
        $this->generate(['name' => ''])->assertHasErrors('name');
        $this->generate(['capacity' => '0'])->assertHasErrors('capacity');
        $this->generate(['capacity' => '-3'])->assertHasErrors('capacity');
        $this->generate(['capacity' => '1000001'])->assertHasErrors('capacity');
        $this->generate(['capacity' => '12.5'])->assertHasErrors('capacity');
        $this->generate(['reward' => '0'])->assertHasErrors('reward');
        $this->generate(['reward' => 'lots'])->assertHasErrors('reward');
        $this->generate(['name' => str_repeat('x', 81)])->assertHasErrors('name');

        $this->assertSame(0, RedeemCampaign::count());
    }

    public function test_a_code_collision_is_resolved_by_the_database_not_a_check_then_insert(): void
    {
        RedeemCampaign::create(['name' => 'Existing', 'code' => 'AAAAA', 'total_capacity' => 1, 'reward_coins' => 1, 'expires_at' => now()->addDay(), 'is_active' => true]);

        $sequence = ['AAAAA', 'AAAAA', 'BBBBB'];
        $generator = \Mockery::mock(RedeemCodeGenerator::class);
        $generator->shouldReceive('generate')->andReturnUsing(function () use (&$sequence) {
            return array_shift($sequence);
        });
        $this->app->instance(RedeemCodeGenerator::class, $generator);

        $this->generate()->assertHasNoErrors();

        $this->assertSame(['AAAAA', 'BBBBB'], RedeemCampaign::orderBy('id')->pluck('code')->all());
    }

    public function test_campaigns_can_be_deactivated_reactivated_archived_and_restored(): void
    {
        $this->generate();
        $campaign = RedeemCampaign::first();
        $list = Livewire::actingAs($this->admin(), 'staff')->test(RedeemCodes::class);

        $list->call('toggle', $campaign->id);
        $this->assertSame('inactive', $campaign->fresh()->status());

        $list->call('toggle', $campaign->id);
        $this->assertSame('active', $campaign->fresh()->status());

        $list->call('archive', $campaign->id);
        $this->assertSame('archived', $campaign->fresh()->status());
        $this->assertFalse($campaign->fresh()->is_active);

        $list->call('unarchive', $campaign->id);
        $this->assertFalse($campaign->fresh()->is_active, 'a restored campaign comes back inactive, never silently live');
        $this->assertDatabaseHas('audit_logs', ['action' => 'redeem_campaign.archived']);
    }

    public function test_the_status_shown_reflects_expiry_and_capacity(): void
    {
        $expired = new RedeemCampaign;
        $expired->forceFill(['name' => 'Old', 'code' => 'OLDOL', 'total_capacity' => 5, 'reward_coins' => 1, 'expires_at' => now()->subHour(), 'is_active' => true])->save();
        $full = new RedeemCampaign;
        $full->forceFill(['name' => 'Full', 'code' => 'FULLL', 'total_capacity' => 2, 'redeemed_count' => 2, 'reward_coins' => 1, 'expires_at' => now()->addDay(), 'is_active' => true])->save();

        $this->assertSame('expired', $expired->status());
        $this->assertSame('exhausted', $full->status());
        $this->assertSame(0, $full->remaining());
    }

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        $this->generate(['name' => 'Alpha promo']);
        $this->generate(['name' => 'Beta promo']);
        $beta = RedeemCampaign::where('name', 'Beta promo')->first();
        $beta->update(['is_active' => false]);

        Livewire::actingAs($this->admin(), 'staff')
            ->test(RedeemCodes::class)
            ->set('search', 'alpha')->assertSee('Alpha promo')->assertDontSee('Beta promo')
            ->set('search', strtolower($beta->code))->assertSee('Beta promo')->assertDontSee('Alpha promo')
            ->set('search', '')->set('status', 'inactive')->assertSee('Beta promo')->assertDontSee('Alpha promo')
            ->set('status', 'active')->assertSee('Alpha promo')->assertDontSee('Beta promo');
    }

    public function test_the_history_lists_attempts_with_usernames_but_no_private_details(): void
    {
        $player = User::factory()->create(['username' => 'lucky_one', 'mobile' => '9876543210']);
        $campaign = new RedeemCampaign;
        $campaign->forceFill(['name' => 'Launch', 'code' => 'K7P4X', 'total_capacity' => 5, 'reward_coins' => 100, 'expires_at' => now()->addDay(), 'is_active' => true])->save();
        RedeemAttempt::create(['user_id' => $player->id, 'redeem_campaign_id' => $campaign->id, 'submitted_code' => 'K7P4X', 'result' => 'success']);
        RedeemAttempt::create(['user_id' => $player->id, 'submitted_code' => 'ZZZZZ', 'result' => 'invalid_code']);

        Livewire::actingAs($this->admin(), 'staff')
            ->test(RedeemHistory::class)
            ->assertSee('lucky_one')
            ->assertSee('Launch')
            ->assertSee('Redeemed')
            ->assertSee('Not a code')
            ->assertDontSee('9876543210')
            ->set('result', 'success')->assertDontSee('Not a code')
            ->set('result', 'failed')->assertSee('Not a code')->assertDontSee('>Redeemed<', false);
    }

    public function test_the_section_needs_redeem_codes_manage_in_both_portals(): void
    {
        $preset = PermissionPreset::create(['name' => 'Other']);
        $preset->permissions()->attach(Permission::firstOrCreate(['key' => 'users.view'], ['label' => 'x', 'group' => 'x']));
        $nobody = StaffUser::factory()->create(['permission_preset_id' => $preset->id]);

        $this->actingAs($nobody, 'staff')->get(route('staff.redeem-codes.index'))->assertForbidden();
        $this->actingAs($nobody, 'staff')->get(route('staff.redeem-codes.history'))->assertForbidden();
        Livewire::actingAs($nobody, 'staff')->test(RedeemCodes::class)->assertForbidden();

        $manager = StaffUser::factory()->create(['permission_preset_id' => tap(PermissionPreset::create(['name' => 'Redeemers']), function ($p) {
            $p->permissions()->attach(Permission::firstWhere('key', 'redeem_codes.manage'));
        })->id]);
        $this->actingAs($manager, 'staff')->get(route('staff.redeem-codes.index'))->assertOk()->assertSee('Redeem codes');
        $this->actingAs($manager, 'staff')->get(route('admin.redeem-codes.index'))->assertForbidden();
        $this->actingAs($this->admin(), 'staff')->get(route('admin.redeem-codes.history'))->assertOk();
    }
}
