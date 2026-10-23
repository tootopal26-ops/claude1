<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\RiskAlert;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard shows genuine, permission-scoped numbers only — never a
 * figure for a section the account cannot open, and never invented data.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function staffWith(string ...$permissionKeys): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'Preset '.uniqid()]);

        foreach ($permissionKeys as $key) {
            $preset->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => explode('.', $key)[0]]));
        }

        return StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
    }

    public function test_the_admin_console_reports_real_counts(): void
    {
        Tournament::factory()->count(2)->create(['status' => 'upcoming']);
        Tournament::factory()->create(['status' => 'ongoing']);
        Tournament::factory()->create(['status' => 'completed']);
        User::factory()->count(3)->create();
        RiskAlert::create(['type' => 'manual_adjustment', 'risk_level' => 'medium', 'details' => []]);

        $admin = StaffUser::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin, 'staff')->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Admin Console')
            ->assertSeeInOrder(['Active tournaments', '3', '2 upcoming · 1 live'])
            ->assertSeeInOrder(['Registered players', '3'])
            ->assertSeeInOrder(['Open risk alerts', '1', 'Awaiting review']);
    }

    public function test_pending_withdrawals_are_counted_and_listed_under_needs_attention(): void
    {
        $player = User::factory()->create();
        WithdrawalRequest::create(['user_id' => $player->id, 'amount' => '10.00', 'upi_id' => 'a@upi', 'status' => 'pending']);
        WithdrawalRequest::create(['user_id' => $player->id, 'amount' => '10.00', 'upi_id' => 'a@upi', 'status' => 'approved']);

        $user = $this->staffWith('withdrawals.manage');

        $this->actingAs($user, 'staff')->get(route('admin.home'))
            ->assertOk()
            ->assertSeeInOrder(['Pending withdrawals', '1', '1 approved or processing'])
            ->assertSee('Withdrawal requests to review');
    }

    public function test_sections_the_account_cannot_open_are_neither_queried_nor_shown(): void
    {
        Tournament::factory()->create(['status' => 'upcoming', 'title' => 'Secret Cup Final']);
        User::factory()->count(2)->create();

        $user = $this->staffWith('legal.manage');

        $this->actingAs($user, 'staff')->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Staff Portal')
            ->assertSee('Your workspace')
            ->assertDontSee('Active tournaments')
            ->assertDontSee('Registered players')
            ->assertDontSee('Secret Cup Final')
            ->assertDontSee('Pending withdrawals');
    }

    public function test_staff_without_audit_access_only_see_their_own_activity(): void
    {
        $viewer = $this->staffWith('legal.manage');
        $other = StaffUser::factory()->create(['is_super_admin' => false]);

        AuditLog::record('own.action_done', $viewer);
        AuditLog::record('someone_elses.secret_action', $other);

        $this->actingAs($viewer, 'staff')->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Your recent activity')
            ->assertSee('own.action_done')
            ->assertDontSee('someone_elses.secret_action');
    }

    public function test_staff_with_audit_access_see_everyones_activity(): void
    {
        $viewer = $this->staffWith('audit_logs.view');
        $other = StaffUser::factory()->create(['is_super_admin' => false]);

        AuditLog::record('someone_elses.visible_action', $other);

        $this->actingAs($viewer, 'staff')->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Recent activity')
            ->assertSee('someone_elses.visible_action');
    }

    public function test_an_account_with_no_permissions_gets_the_friendly_empty_state(): void
    {
        $user = $this->staffWith();

        $this->actingAs($user, 'staff')->get(route('admin.home'))
            ->assertOk()
            ->assertSee('No sections available')
            ->assertSee("doesn't have access to any section");
    }
}
