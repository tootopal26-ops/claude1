<?php

namespace Tests\Feature;

use App\Livewire\Admin\StaffForm;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminStaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_can_create_a_staff_account_with_a_preset(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $preset = PermissionPreset::create(['name' => 'Support Staff']);

        Livewire::actingAs($admin, 'staff')
            ->test(StaffForm::class)
            ->set('display_name', 'New Hire')
            ->set('username', 'new_hire')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('permission_preset_id', $preset->id)
            ->call('save');

        $this->assertDatabaseHas('staff_users', [
            'username' => 'new_hire',
            'permission_preset_id' => $preset->id,
        ]);
    }

    public function test_changing_a_staff_members_preset_invalidates_their_sessions(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $presetA = PermissionPreset::create(['name' => 'Preset A']);
        $presetB = PermissionPreset::create(['name' => 'Preset B']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $presetA->id, 'security_version' => 1]);

        Livewire::actingAs($admin, 'staff')
            ->test(StaffForm::class, ['staffUser' => $staff])
            ->set('permission_preset_id', $presetB->id)
            ->call('save');

        $this->assertSame(2, $staff->fresh()->security_version);
    }

    public function test_an_unrelated_edit_does_not_invalidate_sessions(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $staff = StaffUser::factory()->create(['display_name' => 'Old Name', 'security_version' => 1]);

        Livewire::actingAs($admin, 'staff')
            ->test(StaffForm::class, ['staffUser' => $staff])
            ->set('display_name', 'New Name')
            ->call('save');

        $this->assertSame(1, $staff->fresh()->security_version);
        $this->assertSame('New Name', $staff->fresh()->display_name);
    }

    public function test_deactivating_a_staff_account_invalidates_their_sessions(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $staff = StaffUser::factory()->create(['is_active' => true, 'security_version' => 1]);

        Livewire::actingAs($admin, 'staff')
            ->test(StaffForm::class, ['staffUser' => $staff])
            ->set('is_active', false)
            ->call('save');

        $this->assertFalse($staff->fresh()->is_active);
        $this->assertSame(2, $staff->fresh()->security_version);
    }

    public function test_setting_a_new_password_hashes_it_and_invalidates_sessions(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $staff = StaffUser::factory()->create(['security_version' => 1]);
        $originalHash = $staff->password;

        Livewire::actingAs($admin, 'staff')
            ->test(StaffForm::class, ['staffUser' => $staff])
            ->set('password', 'brand-new-password')
            ->set('password_confirmation', 'brand-new-password')
            ->call('save');

        $staff->refresh();
        $this->assertNotSame($originalHash, $staff->password);
        $this->assertTrue(Hash::check('brand-new-password', $staff->password));
        $this->assertSame(2, $staff->security_version);
    }

    public function test_leaving_the_password_field_blank_on_edit_keeps_the_existing_password(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $staff = StaffUser::factory()->create();
        $originalHash = $staff->password;

        Livewire::actingAs($admin, 'staff')
            ->test(StaffForm::class, ['staffUser' => $staff])
            ->set('display_name', 'Still Me')
            ->call('save');

        $this->assertSame($originalHash, $staff->fresh()->password);
    }

    public function test_a_non_super_admin_cannot_grant_super_admin_through_this_form(): void
    {
        $permission = Permission::create(['key' => 'staff.manage', 'label' => 'Manage staff', 'group' => 'Staff']);
        $preset = PermissionPreset::create(['name' => 'Staff Manager']);
        $preset->permissions()->attach($permission);
        $actingStaff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $target = StaffUser::factory()->create(['is_super_admin' => false]);

        Livewire::actingAs($actingStaff, 'staff')
            ->test(StaffForm::class, ['staffUser' => $target])
            ->set('is_super_admin', true) // attempting to tamper with a property the UI wouldn't expose
            ->call('save');

        $this->assertFalse($target->fresh()->is_super_admin);
    }

    public function test_username_must_be_unique(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        StaffUser::factory()->create(['username' => 'taken_name']);

        Livewire::actingAs($admin, 'staff')
            ->test(StaffForm::class)
            ->set('display_name', 'Someone')
            ->set('username', 'taken_name')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('save')
            ->assertHasErrors('username');
    }
}
