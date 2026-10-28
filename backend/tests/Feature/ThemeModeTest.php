<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Day / Night mode for the Admin and Staff panels: saved per account and per
 * portal, rendered onto <html> so the first paint is right, and never a way
 * around the portal boundary.
 */
class ThemeModeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    private function staff(): StaffUser
    {
        // No permission preset at all: a personal display setting needs none.
        return StaffUser::factory()->create(['is_super_admin' => false, 'permission_preset_id' => null]);
    }

    /**
     * Signed in the way the portal login leaves a session: with the account's security version stamped on it.
     */
    private function signIn(StaffUser $user): static
    {
        $user = $user->fresh();

        return $this->actingAs($user, 'staff')->withSession(['staff_security_version' => $user->security_version]);
    }

    public function test_a_new_account_starts_dark_and_the_page_carries_the_save_url(): void
    {
        $this->actingAs($this->admin(), 'staff')
            ->get('/admin/home')
            ->assertOk()
            ->assertSee('data-portal="admin"', false)
            ->assertSee('data-mode="dark"', false)
            ->assertSee('data-mode-pref="dark"', false)
            ->assertSee('data-mode-url="'.route('admin.appearance.mode').'"', false)
            ->assertSee('name="csrf-token"', false)
            ->assertSee('Switch to Light Mode');
    }

    public function test_saving_light_mode_is_remembered_on_the_next_page_load(): void
    {
        $admin = $this->admin();

        $this->signIn($admin)
            ->putJson('/admin/appearance/mode', ['mode' => 'light'])
            ->assertNoContent();

        $this->assertSame(['admin' => 'light'], $admin->fresh()->theme_modes);
        // A display setting is not an account change: it must not sign the account out of its other sessions.
        $this->assertSame(1, $admin->fresh()->security_version);

        $this->signIn($admin)
            ->get('/admin/tournaments')
            ->assertOk()
            ->assertSee('data-mode="light"', false)
            ->assertSee('data-mode-pref="light"', false);
    }

    public function test_system_default_is_saved_and_resolved_by_the_boot_script(): void
    {
        $admin = $this->admin();

        $this->signIn($admin)->putJson('/admin/appearance/mode', ['mode' => 'system'])->assertNoContent();

        $this->signIn($admin)
            ->get('/admin/home')
            ->assertOk()
            // The server cannot see the device setting, so it paints dark and the inline script corrects it before paint.
            ->assertSee('data-mode="dark"', false)
            ->assertSee('data-mode-pref="system"', false)
            ->assertSee("matchMedia('(prefers-color-scheme: light)')", false);
    }

    public function test_staff_portal_saves_its_own_preference(): void
    {
        $staff = $this->staff();

        $this->signIn($staff)
            ->putJson('/staff/appearance/mode', ['mode' => 'light'])
            ->assertNoContent();

        $this->assertSame(['staff' => 'light'], $staff->fresh()->theme_modes);

        $this->signIn($staff)
            ->get('/staff/home')
            ->assertOk()
            ->assertSee('data-portal="staff"', false)
            ->assertSee('data-mode="light"', false)
            ->assertSee('data-mode-url="'.route('staff.appearance.mode').'"', false);
    }

    public function test_preferences_are_independent_per_account_and_per_portal(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $staff = $this->staff();

        $this->signIn($admin)->putJson('/admin/appearance/mode', ['mode' => 'light'])->assertNoContent();
        $this->signIn($staff)->putJson('/staff/appearance/mode', ['mode' => 'system'])->assertNoContent();

        $this->assertSame(['admin' => 'light'], $admin->fresh()->theme_modes);
        $this->assertSame(['staff' => 'system'], $staff->fresh()->theme_modes);
        $this->assertNull($otherAdmin->fresh()->theme_modes);

        // A saved preference for one portal leaves the other portal's untouched.
        $admin->forceFill(['theme_modes' => ['admin' => 'light', 'staff' => 'dark']])->save();
        $this->signIn($admin)->putJson('/admin/appearance/mode', ['mode' => 'dark'])->assertNoContent();
        $this->assertSame(['admin' => 'dark', 'staff' => 'dark'], $admin->fresh()->theme_modes);

        $this->signIn($otherAdmin)->get('/admin/home')->assertSee('data-mode-pref="dark"', false);
    }

    public function test_only_known_modes_are_accepted(): void
    {
        $admin = $this->admin();

        foreach (['', 'sepia', 'LIGHT', ['light']] as $mode) {
            $this->signIn($admin)
                ->putJson('/admin/appearance/mode', ['mode' => $mode])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('mode');
        }

        $this->assertNull($admin->fresh()->theme_modes);
    }

    public function test_the_save_endpoint_keeps_the_portal_boundary_and_needs_a_session(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();

        $this->signIn($staff)->putJson('/admin/appearance/mode', ['mode' => 'light'])->assertForbidden();
        $this->signIn($admin)->putJson('/staff/appearance/mode', ['mode' => 'light'])->assertForbidden();

        $this->assertNull($staff->fresh()->theme_modes);
        $this->assertNull($admin->fresh()->theme_modes);
    }

    public function test_guests_cannot_save_a_mode(): void
    {
        $this->putJson('/admin/appearance/mode', ['mode' => 'light'])->assertUnauthorized();
        $this->putJson('/staff/appearance/mode', ['mode' => 'light'])->assertUnauthorized();
    }

    public function test_sign_in_pages_follow_the_device_mode_for_their_portal_and_offer_the_toggle(): void
    {
        foreach (['admin', 'staff'] as $portal) {
            $this->get("/{$portal}/login")
                ->assertOk()
                ->assertSee('data-portal="'.$portal.'"', false)
                ->assertSee('data-mode="dark"', false)
                ->assertDontSee('data-mode-url', false)
                // Brand-locked: the stored color theme/font are not applied on sign-in pages, only the mode.
                ->assertDontSee("localStorage.getItem('mm_theme_color')", false)
                ->assertSee("'mm_theme_mode:'", false)
                ->assertSee('x-data="modeToggle"', false);
        }
    }

    public function test_error_pages_use_the_signed_in_accounts_mode(): void
    {
        $staff = $this->staff();
        $staff->forceFill(['theme_modes' => ['staff' => 'light']])->save();

        $this->signIn($staff)
            ->get('/staff/withdrawals')
            ->assertForbidden()
            ->assertSee('data-mode="light"', false)
            ->assertSee('data-portal="staff"', false);
    }
}
