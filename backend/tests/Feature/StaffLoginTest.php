<?php

namespace Tests\Feature;

use App\Livewire\Admin\Login as AdminLogin;
use App\Livewire\Staff\Login as StaffLogin;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sign-in and per-page permission behaviour for accounts in the Staff portal
 * (and the super-admin bypass in the Admin portal). The portal boundary itself
 * is covered by PortalSeparationTest.
 */
class StaffLoginTest extends TestCase
{
    use RefreshDatabase;

    private function presetWith(string $key, string $name = 'Preset'): PermissionPreset
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => explode('.', $key)[0]]);
        $preset = PermissionPreset::create(['name' => $name]);
        $preset->permissions()->attach($permission);

        return $preset;
    }

    public function test_a_super_admin_can_log_in_to_the_admin_portal_with_valid_credentials(): void
    {
        $staff = StaffUser::factory()->create([
            'username' => 'ops_lead',
            'password' => bcrypt('secret123'),
            'is_super_admin' => true,
        ]);

        Livewire::test(AdminLogin::class)
            ->set('username', 'ops_lead')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($staff, 'staff');
    }

    /**
     * Regression test for a real bug found during a QA audit: the
     * post-login landing page (and the bare portal-root redirect) used to
     * be hardcoded to the Tournaments list, which requires
     * tournaments.view — so a legitimately configured staff account
     * missing only that one permission would log in successfully and
     * land straight on a bare 403, with no nav rendered to go anywhere
     * else.
     */
    public function test_logging_in_without_tournaments_view_redirects_to_home_not_tournaments(): void
    {
        StaffUser::factory()->create([
            'username' => 'legal_only',
            'password' => bcrypt('secret123'),
            'permission_preset_id' => $this->presetWith('legal.manage', 'Legal Only')->id,
            'is_super_admin' => false,
        ]);

        Livewire::test(StaffLogin::class)
            ->set('username', 'legal_only')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('staff.home'));
    }

    public function test_a_staff_user_without_tournaments_view_can_load_home_and_only_sees_permitted_sections(): void
    {
        $staff = StaffUser::factory()->create(['permission_preset_id' => $this->presetWith('legal.manage', 'Legal Only')->id, 'is_super_admin' => false]);

        $response = $this->actingAs($staff, 'staff')->get(route('staff.home'));
        $response->assertOk();
        $response->assertSee('Legal');
        $response->assertDontSee('Tournaments');

        $this->actingAs($staff, 'staff')->get('/staff')->assertRedirect(route('staff.home'));
    }

    public function test_the_home_page_shows_a_friendly_message_for_a_staff_user_with_no_permissions(): void
    {
        $preset = PermissionPreset::create(['name' => 'Nothing']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $response = $this->actingAs($staff, 'staff')->get(route('staff.home'));

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

        Livewire::test(StaffLogin::class)
            ->set('username', 'disabled_ops')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasErrors('form');

        $this->assertGuest('staff');
    }

    public function test_a_guest_is_redirected_to_login_from_a_protected_route(): void
    {
        $this->get(route('staff.tournaments.index'))->assertRedirect(route('staff.login'));
        $this->get(route('admin.tournaments.index'))->assertRedirect(route('admin.login'));
    }

    public function test_a_staff_user_without_the_required_permission_is_denied(): void
    {
        $preset = PermissionPreset::create(['name' => 'Support Only']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.tournaments.create'))->assertForbidden();
    }

    public function test_a_staff_user_with_the_required_permission_is_allowed(): void
    {
        $staff = StaffUser::factory()->create([
            'permission_preset_id' => $this->presetWith('tournaments.manage', 'Tournament Manager')->id,
            'is_super_admin' => false,
        ]);

        $this->actingAs($staff, 'staff')->get(route('staff.tournaments.create'))->assertOk();
    }

    public function test_a_super_admin_bypasses_granular_permission_checks(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->actingAs($staff, 'staff')->get(route('admin.tournaments.create'))->assertOk();
    }
}
