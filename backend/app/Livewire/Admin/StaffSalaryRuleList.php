<?php

namespace App\Livewire\Admin;

use App\Models\StaffSalaryRule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class StaffSalaryRuleList extends Component
{
    public function render()
    {
        $rules = StaffSalaryRule::with('staffUser')->orderBy('staff_user_id')->orderBy('action_type')->get();

        return view('livewire.admin.staff-salary-rule-list', compact('rules'));
    }
}
