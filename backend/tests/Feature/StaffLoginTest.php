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
            ->call('login')
            ->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($staff, 'staff');
    }

    /**
     * Regression test for a real bug found during a QA audit: the
     * post-login landing page (and the bare `/admin` redirect) used to
     * be hardcoded to the Tournaments list, which requires
     * tournaments.view — so a legitimately configured staff account
     * missing only that one permission would log in successfully and
     * land straight on a bare 403, with no nav rendered to go anywhere
     * else.
     */
    public function test_logging_in_without_tournaments_view_redirects_to_home_not_tournaments(): void
    {
        $permission = Permission::create(['key' => 'legal.manage', 'label' => 'Manage legal docs', 'group' => 'legal']);
        $preset = PermissionPreset::create(['name' => 'Legal Only']);
        $preset->permissions()->attach($permission);
        StaffUser::factory()->create([
            'username' => 'legal_only',
            'password' => bcrypt('secret123'),
            'permission_preset_id' => $preset->id,
            'is_super_admin' => false,
        ]);

        Livewire::test(Login::class)
            ->set('username', 'legal_only')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('admin.home'));
    }

    public function test_a_staff_user_without_tournaments_view_can_load_home_and_only_sees_permitted_sections(): void
    {
        $permission = Permission::create(['key' => 'legal.manage', 'label' => 'Manage legal docs', 'group' => 'legal']);
        $preset = PermissionPreset::create(['name' => 'Legal Only']);
        $preset->permissions()->attach($permission);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $response = $this->actingAs($staff, 'staff')->get(route('admin.home'));
        $response->assertOk();
        $response->assertSee('Legal');
        $response->assertDontSee('Tournaments');

        $this->actingAs($staff, 'staff')->get('/admin')->assertRedirect(route('admin.home'));
    }

    public function test_the_home_page_shows_a_friendly_message_for_a_staff_user_with_no_permissions(): void
    {
        $preset = PermissionPreset::create(['name' => 'Nothing']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $response = $this->actingAs($staff, 'staff')->get(route('admin.home'));

        $response->assertOk();
        $response->assertSee('doesn\'t have access to any section');
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
