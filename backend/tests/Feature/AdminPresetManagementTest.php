<?php

namespace Tests\Feature;

use App\Livewire\Admin\PresetForm;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPresetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_user_without_staff_manage_cannot_reach_preset_management(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.presets.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_preset_with_permissions(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $viewPerm = Permission::create(['key' => 'tournaments.view', 'label' => 'View', 'group' => 'Tournaments']);
        $managePerm = Permission::create(['key' => 'tournaments.manage', 'label' => 'Manage', 'group' => 'Tournaments']);

        Livewire::actingAs($admin, 'staff')
            ->test(PresetForm::class)
            ->set('name', 'Junior Manager')
            ->set('description', 'View and manage tournaments')
            ->set('selectedPermissionIds', [$viewPerm->id, $managePerm->id])
            ->call('save');

        $preset = PermissionPreset::where('name', 'Junior Manager')->firstOrFail();
        $this->assertSame(2, $preset->permissions()->count());
    }

    public function test_editing_a_preset_replaces_its_permission_set(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $permA = Permission::create(['key' => 'a.perm', 'label' => 'A', 'group' => 'G']);
        $permB = Permission::create(['key' => 'b.perm', 'label' => 'B', 'group' => 'G']);
        $preset = PermissionPreset::create(['name' => 'Editable']);
        $preset->permissions()->attach($permA);

        Livewire::actingAs($admin, 'staff')
            ->test(PresetForm::class, ['preset' => $preset])
            ->set('selectedPermissionIds', [$permB->id])
            ->call('save');

        $preset->refresh();
        $this->assertSame([$permB->id], $preset->permissions()->pluck('permissions.id')->all());
    }

    public function test_preset_names_must_be_unique(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        PermissionPreset::create(['name' => 'Existing']);

        Livewire::actingAs($admin, 'staff')
            ->test(PresetForm::class)
            ->set('name', 'Existing')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_a_staff_member_assigned_a_preset_can_use_only_its_permissions(): void
    {
        $permission = Permission::create(['key' => 'tournaments.view', 'label' => 'View', 'group' => 'Tournaments']);
        $preset = PermissionPreset::create(['name' => 'Viewer Only']);
        $preset->permissions()->attach($permission);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.tournaments.index'))->assertOk();
        $this->actingAs($staff, 'staff')->get(route('staff.tournaments.create'))->assertForbidden();
    }

    /**
     * A Livewire action is a separate AJAX endpoint from the page's
     * initial GET, so route middleware alone (proven by the test above)
     * doesn't gate it — the component must check permission itself.
     */
    public function test_saving_a_preset_is_denied_without_staff_manage_even_via_a_direct_livewire_call(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        Livewire::actingAs($staff, 'staff')
            ->test(PresetForm::class)
            ->set('name', 'Should Not Save')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('permission_presets', ['name' => 'Should Not Save']);
    }

    public function test_creating_a_preset_is_audit_logged(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        Livewire::actingAs($admin, 'staff')
            ->test(PresetForm::class)
            ->set('name', 'Logged Preset')
            ->call('save');

        $this->assertDatabaseHas('audit_logs', ['action' => 'permission_preset.created', 'actor_id' => $admin->id]);
    }
}
