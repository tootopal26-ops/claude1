<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\StaffSalaryRule;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class StaffSalaryRuleForm extends Component
{
    public ?StaffSalaryRule $rule = null;

    public ?int $staff_user_id = null;

    public string $action_type = '';

    public float $amount_per_action = 0;

    public bool $is_active = true;

    public function mount(?StaffSalaryRule $rule = null): void
    {
        $this->rule = $rule;

        if ($rule !== null) {
            $this->staff_user_id = $rule->staff_user_id;
            $this->action_type = $rule->action_type;
            $this->amount_per_action = (float) $rule->amount_per_action;
            $this->is_active = $rule->is_active;
        }
    }

    public function save(): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('salary.manage'), 403);

        $validated = $this->validate([
            'staff_user_id' => ['required', 'exists:staff_users,id'],
            'action_type' => ['required', 'string', 'max:255'],
            'amount_per_action' => ['required', 'numeric', 'min:0.01'],
            'is_active' => ['boolean'],
        ]);

        $rule = $this->rule ?? new StaffSalaryRule(['created_by_staff_id' => Auth::guard('staff')->id()]);
        $rule->fill($validated);
        $rule->save();

        AuditLog::record('staff_salary.rule_saved', Auth::guard('staff')->user(), $rule, [
            'staff_user_id' => $rule->staff_user_id,
            'action_type' => $rule->action_type,
        ]);

        session()->flash('status', 'Salary rule saved.');

        $this->redirectRoute('admin.staff-salary.rules.index', navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.staff-salary-rule-form', [
            'staffUsers' => StaffUser::orderBy('display_name')->get(),
            'actionTypes' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
