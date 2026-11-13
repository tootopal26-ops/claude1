<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\DepositBonusSettings;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DepositBonusSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    private function unprivilegedStaff(): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'Nothing']);

        return StaffUser::factory()->create(['permission_preset_id' => $preset->id]);
    }

    public function test_an_admin_with_permission_can_save_the_settings(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'staff')
            ->test(DepositBonusSettings::class)
            ->set('values.deposit_bonus_enabled', true)
            ->set('values.deposit_bonus_amount', '50.00')
            ->set('values.deposit_bonus_min_deposit', '50.00')
            ->set('values.deposit_bonus_max_per_player', '')
            ->set('values.deposit_bonus_first_deposit_only', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) config('minimax.deposit_bonus_enabled'));
        $this->assertSame('50.00', config('minimax.deposit_bonus_amount'));
        $this->assertNull(config('minimax.deposit_bonus_max_per_player'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_settings.updated']);
    }

    public function test_the_route_requires_the_permission(): void
    {
        $staff = $this->unprivilegedStaff();

        $this->actingAs($staff, 'staff')
            ->get(route('admin.deposit-bonus.index'))
            ->assertForbidden();
    }

    public function test_an_admin_can_reach_the_page_and_see_history(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'staff')
            ->get(route('admin.deposit-bonus.index'))
            ->assertOk()
            ->assertSee('Deposit Bonus Management');
    }
}
