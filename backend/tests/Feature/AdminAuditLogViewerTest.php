<?php

namespace Tests\Feature;

use App\Livewire\Admin\AuditLogViewer;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_user_without_audit_logs_view_is_denied(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('admin.audit-logs.index'))->assertForbidden();
    }

    public function test_a_staff_user_with_audit_logs_view_sees_recorded_events(): void
    {
        $permission = Permission::create(['key' => 'audit_logs.view', 'label' => 'View audit logs', 'group' => 'Reports']);
        $preset = PermissionPreset::create(['name' => 'Auditor']);
        $preset->permissions()->attach($permission);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);

        $user = User::factory()->create(['username' => 'traceable_player']);
        $tournament = Tournament::factory()->create();
        AuditLog::record('tournament.joined', $user, $tournament, ['note' => 'test']);

        $response = $this->actingAs($staff, 'staff')->get(route('admin.audit-logs.index'));

        $response->assertOk()
            ->assertSee('traceable_player')
            ->assertSee('tournament.joined');
    }

    public function test_filtering_by_action_only_shows_matching_entries(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create(['username' => 'the_joiner']);
        AuditLog::record('tournament.joined', $user);
        AuditLog::record('tournament.created', $admin);

        // The action dropdown itself always lists every distinct action
        // (so you can switch filters), so asserting on the actor's name
        // distinguishes "this row is filtered out" from "this action
        // string merely appears as a <option> elsewhere on the page".
        Livewire::actingAs($admin, 'staff')
            ->test(AuditLogViewer::class)
            ->set('action', 'tournament.created')
            ->assertSee($admin->display_name)
            ->assertDontSee('the_joiner');
    }

    public function test_a_system_triggered_event_with_no_actor_shows_as_system(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        AuditLog::record('scheduled.job_ran', null);

        Livewire::actingAs($admin, 'staff')
            ->test(AuditLogViewer::class)
            ->assertSee('System');
    }
}
