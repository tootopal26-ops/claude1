<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Referrals\History;
use App\Livewire\Admin\Referrals\Settings;
use App\Models\AuditLog;
use App\Models\PermissionPreset;
use App\Models\Referral;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReferralManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): StaffUser
    {
        return StaffUser::factory()->superAdmin()->create();
    }

    private function staffWithoutPermission(): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'No referral access']);

        return StaffUser::factory()->create(['permission_preset_id' => $preset->id]);
    }

    public function test_an_admin_can_enable_the_system_and_set_both_reward_amounts(): void
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(Settings::class)
            ->set('values.referral_enabled', true)
            ->set('values.referral_new_player_reward', '25.00')
            ->set('values.referral_referrer_reward', '35.00')
            ->set('values.referral_max_per_referrer', '10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) config('minimax.referral_enabled'));
        $this->assertSame('25.00', (string) config('minimax.referral_new_player_reward'));
        $this->assertSame('35.00', (string) config('minimax.referral_referrer_reward'));
        $this->assertSame(10, config('minimax.referral_max_per_referrer'));
    }

    public function test_a_staff_account_without_the_permission_cannot_change_settings(): void
    {
        Livewire::actingAs($this->staffWithoutPermission(), 'staff')
            ->test(Settings::class)
            ->set('values.referral_enabled', true)
            ->call('save');

        $this->assertFalse((bool) config('minimax.referral_enabled'));
    }

    public function test_a_staff_account_without_the_permission_cannot_open_either_page(): void
    {
        $staff = $this->staffWithoutPermission();

        $this->actingAs($staff, 'staff')->get(route('staff.referrals.index'))->assertForbidden();
        $this->actingAs($staff, 'staff')->get(route('staff.referrals.history'))->assertForbidden();
    }

    public function test_history_shows_every_completed_referral_and_can_be_searched(): void
    {
        $admin = $this->admin();
        $referrer = User::factory()->create(['username' => 'findable_referrer']);
        $referred = User::factory()->create(['username' => 'someone_else']);

        Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code_used' => $referrer->referral_code,
            'new_player_reward' => '20.00',
            'referrer_reward' => '30.00',
            'status' => 'completed',
        ]);

        Livewire::actingAs($admin, 'staff')
            ->test(History::class)
            ->assertSee('findable_referrer')
            ->assertSee('someone_else')
            ->set('search', 'findable_referrer')
            ->assertSee('findable_referrer')
            ->set('search', 'no-such-player-xyz')
            ->assertDontSee('findable_referrer');
    }

    public function test_settings_changes_are_audit_logged(): void
    {
        Livewire::actingAs($this->admin(), 'staff')
            ->test(Settings::class)
            ->set('values.referral_enabled', true)
            ->call('save');

        $this->assertTrue(AuditLog::where('action', 'platform_settings.updated')
            ->where('metadata->key', 'referral_enabled')
            ->exists());
    }
}
