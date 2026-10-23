<?php

namespace Tests\Feature\Hardening;

use App\Livewire\Admin\RiskAlertList;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\RiskAlert;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminRiskAlertUiTest extends TestCase
{
    use RefreshDatabase;

    private function grantPreset(array $keys): PermissionPreset
    {
        $preset = PermissionPreset::create(['name' => implode('+', $keys).'-'.uniqid()]);

        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'Test']);
            $preset->permissions()->attach($permission);
        }

        return $preset;
    }

    public function test_the_index_requires_audit_logs_view(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.risk-alerts.index'))->assertForbidden();
    }

    public function test_mark_reviewed_is_denied_without_the_permission(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);
        $alert = RiskAlert::create(['type' => 'test_alert', 'risk_level' => 'medium']);

        Livewire::actingAs($staff, 'staff')
            ->test(RiskAlertList::class)
            ->call('markReviewed', $alert->id)
            ->assertForbidden();

        $this->assertTrue($alert->fresh()->isOpen());
    }

    public function test_a_staff_user_with_the_permission_can_view_and_mark_reviewed(): void
    {
        $preset = $this->grantPreset(['audit_logs.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $alert = RiskAlert::create(['type' => 'test_alert', 'risk_level' => 'high']);

        Livewire::actingAs($staff, 'staff')
            ->test(RiskAlertList::class)
            ->assertSee('test_alert')
            ->call('markReviewed', $alert->id);

        $alert->refresh();
        $this->assertFalse($alert->isOpen());
        $this->assertSame($staff->id, $alert->reviewed_by_staff_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk_alert.reviewed', 'actor_id' => $staff->id]);
    }

    public function test_marking_an_already_reviewed_alert_again_is_idempotent(): void
    {
        $preset = $this->grantPreset(['audit_logs.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $alert = RiskAlert::create(['type' => 'test_alert', 'risk_level' => 'low']);

        $component = Livewire::actingAs($staff, 'staff')->test(RiskAlertList::class);
        $component->call('markReviewed', $alert->id);
        $firstReviewedAt = $alert->fresh()->reviewed_at;

        $component->call('markReviewed', $alert->id);

        $this->assertSame(1, AuditLog::where('action', 'risk_alert.reviewed')->count());
        $this->assertEquals($firstReviewedAt, $alert->fresh()->reviewed_at);
    }

    public function test_filters_narrow_the_list_without_error(): void
    {
        // The type filter dropdown always lists every distinct type so the
        // user can switch filters, so asserting on the raw type string would
        // also match the <option> itself regardless of which row is shown.
        // Each alert gets a distinct subject so the rendered "Subject"
        // column gives an unambiguous, filter-independent needle instead.
        $preset = $this->grantPreset(['audit_logs.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        RiskAlert::create(['type' => 'type_a', 'risk_level' => 'high', 'subject_type' => 'App\\Models\\User', 'subject_id' => 111]);
        RiskAlert::create(['type' => 'type_b', 'risk_level' => 'low', 'subject_type' => 'App\\Models\\User', 'subject_id' => 222]);

        Livewire::actingAs($staff, 'staff')
            ->test(RiskAlertList::class)
            ->set('type', 'type_a')
            ->assertSee('User #111')
            ->assertDontSee('User #222');

        Livewire::actingAs($staff, 'staff')
            ->test(RiskAlertList::class)
            ->set('riskLevel', 'low')
            ->assertDontSee('User #111')
            ->assertSee('User #222');
    }
}
