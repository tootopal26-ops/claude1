<?php

namespace Tests\Feature\StaffSalary;

use App\Livewire\Admin\StaffSalaryRuleForm;
use App\Livewire\Admin\StaffSalarySettlementShow;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffSalarySettlement;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminStaffSalaryUiTest extends TestCase
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

    public function test_a_staff_user_without_the_permission_cannot_view_rules(): void
    {
        $staff = StaffUser::factory()->create(['is_super_admin' => false]);

        $this->actingAs($staff, 'staff')->get(route('staff.staff-salary.rules.index'))->assertForbidden();
    }

    public function test_saving_a_rule_is_denied_without_the_permission(): void
    {
        $preset = $this->grantPreset(['salary.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $target = StaffUser::factory()->create();

        Livewire::actingAs($staff, 'staff')
            ->test(StaffSalaryRuleForm::class)
            ->set('staff_user_id', $target->id)
            ->set('action_type', 'tournament.created')
            ->set('amount_per_action', '10.00')
            ->call('save')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_create_a_rule(): void
    {
        $admin = StaffUser::factory()->create(['is_super_admin' => true]);
        $target = StaffUser::factory()->create();

        Livewire::actingAs($admin, 'staff')
            ->test(StaffSalaryRuleForm::class)
            ->set('staff_user_id', $target->id)
            ->set('action_type', 'tournament.created')
            ->set('amount_per_action', '10.00')
            ->call('save')
            ->assertRedirect(route('admin.staff-salary.rules.index'));

        $this->assertDatabaseHas('staff_salary_rules', ['staff_user_id' => $target->id, 'action_type' => 'tournament.created']);
    }

    public function test_mark_paid_is_denied_without_the_approve_permission(): void
    {
        $preset = $this->grantPreset(['salary.view']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $settlement = StaffSalarySettlement::create([
            'staff_user_id' => StaffUser::factory()->create()->id,
            'period_start' => '2026-01-12 00:00:00',
            'period_end' => '2026-01-19 00:00:00',
            'status' => 'pending',
            'total_amount' => '50.00',
        ]);

        Livewire::actingAs($staff, 'staff')
            ->test(StaffSalarySettlementShow::class, ['settlement' => $settlement])
            ->set('paymentReference', 'REF1')
            ->call('markPaid')
            ->assertForbidden();

        $this->assertSame('pending', $settlement->fresh()->status);
    }

    public function test_a_staff_user_with_approve_can_mark_a_settlement_paid(): void
    {
        $preset = $this->grantPreset(['salary.view', 'salary.approve']);
        $staff = StaffUser::factory()->create(['permission_preset_id' => $preset->id, 'is_super_admin' => false]);
        $settlement = StaffSalarySettlement::create([
            'staff_user_id' => StaffUser::factory()->create()->id,
            'period_start' => '2026-01-12 00:00:00',
            'period_end' => '2026-01-19 00:00:00',
            'status' => 'pending',
            'total_amount' => '50.00',
        ]);

        Livewire::actingAs($staff, 'staff')
            ->test(StaffSalarySettlementShow::class, ['settlement' => $settlement])
            ->set('paymentReference', 'REF1')
            ->call('markPaid');

        $this->assertSame('paid', $settlement->fresh()->status);
    }
}
