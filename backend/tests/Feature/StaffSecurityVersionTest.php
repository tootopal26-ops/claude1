<?php

namespace Tests\Feature;

use App\Livewire\Admin\Login;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffSecurityVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_logging_in_stamps_the_session_with_the_accounts_current_security_version(): void
    {
        $staff = StaffUser::factory()->create([
            'username' => 'ops_lead',
            'password' => bcrypt('secret123'),
            'is_super_admin' => true,
            'security_version' => 3,
        ]);

        Livewire::test(Login::class)
            ->set('username', 'ops_lead')
            ->set('password', 'secret123')
            ->call('login');

        $this->assertSame(3, session('staff_security_version'));
        $this->assertAuthenticatedAs($staff, 'staff');
    }

    public function test_a_session_stamped_with_an_old_security_version_is_forced_to_log_in_again(): void
    {
        $staff = StaffUser::factory()->create(['security_version' => 5, 'is_super_admin' => true]);

        // Simulates a session that logged in before the account was later
        // changed (preset/active flag/password) and security_version bumped.
        $response = $this->withSession(['staff_security_version' => 1])
            ->actingAs($staff, 'staff')
            ->get(route('admin.tournaments.index'));

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest('staff');
    }

    public function test_a_session_with_the_current_security_version_is_let_through(): void
    {
        $staff = StaffUser::factory()->create(['security_version' => 5, 'is_super_admin' => true]);

        $response = $this->withSession(['staff_security_version' => 5])
            ->actingAs($staff, 'staff')
            ->get(route('admin.tournaments.index'));

        $response->assertOk();
    }

    public function test_invalidate_active_sessions_bumps_the_version(): void
    {
        $staff = StaffUser::factory()->create(['security_version' => 1]);

        $staff->invalidateActiveSessions();

        $this->assertSame(2, $staff->fresh()->security_version);
    }
}
