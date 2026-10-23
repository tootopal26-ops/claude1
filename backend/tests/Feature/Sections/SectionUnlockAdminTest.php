<?php

namespace Tests\Feature\Sections;

use App\Domain\Sections\SectionPricingService;
use App\Livewire\Admin\SectionUnlockPricing;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\SectionUnlock;
use App\Models\SectionUnlockPrice;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SectionUnlockAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    public function test_the_page_lists_only_registered_sections_and_starts_free(): void
    {
        $this->actingAs($this->admin(), 'staff')
            ->get(route('admin.section-unlock-pricing.index'))
            ->assertOk()
            ->assertSee('Section unlock pricing')
            ->assertSee('Leaderboard')
            ->assertSee('Currently free')
            ->assertSee('Nothing is locked until you turn paid unlocking on');
    }

    public function test_saving_a_price_stores_it_enables_unlocking_and_writes_an_audit_entry(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'staff')
            ->test(SectionUnlockPricing::class)
            ->assertSet('hasChanges', false)
            ->set('enabled', true)
            ->set('price', '250')
            ->assertSet('hasChanges', true)
            ->call('save')
            ->assertHasNoErrors();

        $row = SectionUnlockPrice::where('section_key', 'leaderboard')->sole();
        $this->assertSame(250, $row->price_coins);
        $this->assertTrue($row->is_enabled);
        $this->assertSame($admin->id, $row->updated_by_staff_id);

        $log = AuditLog::where('action', 'section_unlock.updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['section' => 'leaderboard', 'old_price' => null, 'new_price' => 250, 'old_enabled' => false, 'new_enabled' => true], $log->metadata);
    }

    public function test_the_preview_and_history_show_the_saved_price(): void
    {
        $admin = $this->admin();
        SectionUnlockPrice::factory()->create(['price_coins' => 250]);
        app(SectionPricingService::class)->save($admin, 'leaderboard', 400, true);

        Livewire::actingAs($admin, 'staff')
            ->test(SectionUnlockPricing::class)
            ->assertSet('price', '400')
            ->assertSet('enabled', true)
            ->assertSee('Unlock · 400 Coins')
            ->assertSee('what players who have not unlocked it see now')
            ->assertSeeInOrder(['price', '250', '→', '400', 'coins']);
    }

    public function test_the_draft_preview_follows_the_form_before_saving(): void
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(SectionUnlockPricing::class)
            ->set('enabled', true)
            ->set('price', '125')
            ->assertSee('Unlock · 125 Coins')
            ->assertSee('Unsaved changes')
            ->set('enabled', false)
            ->assertDontSee('Unlock · 125 Coins');
    }

    public function test_every_real_change_is_audited_and_a_no_op_save_is_not(): void
    {
        $admin = $this->admin();
        $service = app(SectionPricingService::class);

        $this->assertTrue($service->save($admin, 'leaderboard', 250, true));
        $this->assertFalse($service->save($admin, 'leaderboard', 250, true));
        $this->assertTrue($service->save($admin, 'leaderboard', 300, true));
        $this->assertTrue($service->save($admin, 'leaderboard', 300, false));

        $this->assertSame(3, AuditLog::where('action', 'section_unlock.updated')->count());
        $this->assertSame(1, SectionUnlockPrice::count());
        $this->assertSame(['old_price' => 300, 'new_price' => 300, 'old_enabled' => true, 'new_enabled' => false], collect(AuditLog::latest('id')->first()->metadata)->only(['old_price', 'new_price', 'old_enabled', 'new_enabled'])->all());
    }

    public function test_invalid_prices_are_rejected(): void
    {
        foreach (['', '0', '-5', '12.5', 'abc', '1000001'] as $bad) {
            Livewire::actingAs($this->admin(), 'staff')
                ->test(SectionUnlockPricing::class)
                ->set('enabled', true)
                ->set('price', $bad)
                ->call('save')
                ->assertHasErrors('price');
        }

        $this->assertSame(0, SectionUnlockPrice::count());
    }

    public function test_an_unregistered_section_cannot_be_priced(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SectionPricingService::class)->save($this->admin(), 'made-up-section', 100, true);
    }

    public function test_picking_a_section_outside_the_registry_falls_back_to_a_registered_one(): void
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(SectionUnlockPricing::class, ['sectionKey' => 'bogus'])
            ->assertSet('sectionKey', 'leaderboard')
            ->set('sectionKey', 'something-else')
            ->assertSet('sectionKey', 'leaderboard');
    }

    public function test_pricing_one_section_never_touches_another(): void
    {
        config(['minimax.unlockable_sections.arena' => ['title' => 'Arena', 'description' => 'Other.', 'icon' => 'tournaments']]);
        $arena = SectionUnlockPrice::factory()->create(['section_key' => 'arena', 'price_coins' => 80, 'is_enabled' => true]);
        $admin = $this->admin();

        app(SectionPricingService::class)->save($admin, 'leaderboard', 500, true);
        app(SectionPricingService::class)->save($admin, 'leaderboard', 700, false);

        $arena->refresh();
        $this->assertSame(80, $arena->price_coins);
        $this->assertTrue($arena->is_enabled);
        $this->assertNull($arena->updated_by_staff_id);
    }

    public function test_changing_the_price_leaves_what_players_already_bought_alone(): void
    {
        $admin = $this->admin();
        $unlock = SectionUnlock::factory()->create(['price_paid' => 250]);

        app(SectionPricingService::class)->save($admin, 'leaderboard', 900, true);
        app(SectionPricingService::class)->save($admin, 'leaderboard', 900, false);

        $this->assertSame(250, $unlock->fresh()->price_paid);
        $this->assertSame(1, SectionUnlock::count());
    }

    public function test_turning_paid_unlocking_on_asks_for_confirmation_and_changing_a_live_price_does_not(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'staff')
            ->test(SectionUnlockPricing::class)
            ->set('enabled', true)
            ->set('price', '250')
            ->assertSeeHtml('data-confirm="Players who have not unlocked Leaderboard will be locked out of it until they pay. Turn paid unlocking on?"');

        app(SectionPricingService::class)->save($admin, 'leaderboard', 250, true);

        Livewire::actingAs($admin, 'staff')
            ->test(SectionUnlockPricing::class)
            ->set('price', '300')
            ->assertDontSeeHtml('data-confirm="Players who have not unlocked');
    }

    public function test_the_page_needs_section_unlocks_manage_in_both_portals_and_the_nav_follows_it(): void
    {
        $preset = PermissionPreset::create(['name' => 'Other']);
        $preset->permissions()->attach(Permission::firstOrCreate(['key' => 'users.view'], ['label' => 'x', 'group' => 'x']));
        $nobody = StaffUser::factory()->create(['permission_preset_id' => $preset->id]);

        $this->actingAs($nobody, 'staff')->get(route('staff.section-unlock-pricing.index'))->assertForbidden();
        Livewire::actingAs($nobody, 'staff')->test(SectionUnlockPricing::class)->assertForbidden();
        $this->actingAs($nobody, 'staff')->get(route('staff.home'))->assertOk()->assertDontSee('Section Unlock Pricing');

        $manager = StaffUser::factory()->create(['permission_preset_id' => tap(PermissionPreset::create(['name' => 'Pricers']), function ($p) {
            $p->permissions()->attach(Permission::firstWhere('key', 'section_unlocks.manage'));
        })->id]);

        $this->actingAs($manager, 'staff')->get(route('staff.section-unlock-pricing.index'))->assertOk()->assertSee('Section unlock pricing');
        $this->actingAs($manager, 'staff')->get(route('staff.home'))->assertSee('Section Unlock Pricing');
        $this->actingAs($manager, 'staff')->get(route('admin.section-unlock-pricing.index'))->assertForbidden();
        $this->actingAs($this->admin(), 'staff')->get(route('admin.home'))->assertSee('Section Unlock Pricing');
    }

    public function test_guests_are_sent_to_the_right_login(): void
    {
        $this->get(route('admin.section-unlock-pricing.index'))->assertRedirect(route('admin.login'));
        $this->get(route('staff.section-unlock-pricing.index'))->assertRedirect(route('staff.login'));
    }
}
