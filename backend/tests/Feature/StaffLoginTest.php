<?php

namespace Tests\Feature;

use App\Livewire\Admin\Login;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_user_can_log_in_with_valid_credentials(): void
    {
        $staff = StaffUser::factory()->create([
            'username' => 'ops_lead',
            'password' => bcrypt('secret123'),
            'is_super_admin' => true,
        ]);

        Livewire::test(Login::class)
            ->set('username', 'ops_lead')
            ->set('password', 'secret123')
            ->call('login');

        $this->assertAuthenticatedAs($staff, 'staff');
    }

    public function test_an_inactive_staff_account_cannot_log_in(): void
    {
        StaffUser::factory()->create([
            'username' => 'disabled_ops',
            'password' => bcrypt('secret123'),
            'is_active' => false,
        ]);

        Livewire::test(Login::class)
            ->set('username', 'disabled_ops')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasErrors('username');

        $this->assertGuest('staff');
    }

    public function test_a_guest_is_redirected_to_login_from_an_admin_route(): void
    {
        $response = $this->get(route('admin.tournaments.index'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_a_staff_user_without_the_required_permission_is_denied(): void
    {
        $preset = PermissionPreset::create(['name' => 'Support Only']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $response = $this->actingAs($staff, 'staff')->get(route('admin.tournaments.create'));

        $response->assertForbidden();
    }

    public function test_a_staff_user_with_the_required_permission_is_allowed(): void
    {
        $permission = Permission::create(['key' => 'tournaments.manage', 'label' => 'Manage tournaments', 'group' => 'tournaments']);
        $preset = PermissionPreset::create(['name' => 'Tournament Manager']);
        $preset->permissions()->attach($permission);

        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $response = $this->actingAs($staff, 'staff')->get(route('admin.tournaments.create'));

        $response->assertOk();
    }

    public function test_a_super_admin_bypasses_granular_permission_checks(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($staff, 'staff')->get(route('admin.tournaments.create'));

        $response->assertOk();
    }
}
