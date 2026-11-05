<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ProfileSecurity;
use App\Models\AuditLog;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_member_can_change_their_own_password(): void
    {
        $staff = StaffUser::factory()->create(['password' => 'old-password-123']);

        Livewire::actingAs($staff, 'staff')
            ->test(ProfileSecurity::class)
            ->set('currentPassword', 'old-password-123')
            ->set('password', 'new-password-456')
            ->set('password_confirmation', 'new-password-456')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password-456', $staff->fresh()->password));
        $this->assertTrue(AuditLog::where('action', 'staff.password_changed')->exists());
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $staff = StaffUser::factory()->create(['password' => 'old-password-123']);

        Livewire::actingAs($staff, 'staff')
            ->test(ProfileSecurity::class)
            ->set('currentPassword', 'wrong-password')
            ->set('password', 'new-password-456')
            ->set('password_confirmation', 'new-password-456')
            ->call('save')
            ->assertHasErrors('currentPassword');

        $this->assertTrue(Hash::check('old-password-123', $staff->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_at_least_8_characters(): void
    {
        $staff = StaffUser::factory()->create(['password' => 'old-password-123']);

        Livewire::actingAs($staff, 'staff')
            ->test(ProfileSecurity::class)
            ->set('currentPassword', 'old-password-123')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('save')
            ->assertHasErrors('password');

        Livewire::actingAs($staff, 'staff')
            ->test(ProfileSecurity::class)
            ->set('currentPassword', 'old-password-123')
            ->set('password', 'new-password-456')
            ->set('password_confirmation', 'does-not-match')
            ->call('save')
            ->assertHasErrors('password');
    }

    public function test_the_page_needs_no_special_permission_just_an_authenticated_staff_account(): void
    {
        $staff = StaffUser::factory()->create();

        $this->actingAs($staff, 'staff')->get(route('staff.profile.security'))->assertOk();
    }

    public function test_changing_the_password_bumps_the_security_version_and_refreshes_this_sessions_own_stamp(): void
    {
        // Same mechanism StaffForm uses when an admin changes someone else's password
        // (AdminStaffManagementTest::test_setting_a_new_password_hashes_it_and_invalidates_sessions)
        // — a self-service change must invalidate every OTHER active session for this
        // account too, per StaffUser::invalidateActiveSessions()'s own documented contract.
        $staff = StaffUser::factory()->create(['password' => 'old-password-123', 'security_version' => 1]);

        $this->withSession(['staff_security_version' => 1]);

        Livewire::actingAs($staff, 'staff')
            ->test(ProfileSecurity::class)
            ->set('currentPassword', 'old-password-123')
            ->set('password', 'new-password-456')
            ->set('password_confirmation', 'new-password-456')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, $staff->fresh()->security_version);

        // Any session still stamped with the old version (any other device) is now stale and
        // will be forced to log in again on its next request — see StaffSecurityVersionTest.
        // This session's own stamp was refreshed in the same request, so it stays signed in.
        $this->assertSame(2, session('staff_security_version'));
    }
}
