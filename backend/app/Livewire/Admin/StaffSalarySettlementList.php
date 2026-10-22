<?php

namespace App\Livewire\Admin;

use App\Models\StaffSalarySettlement;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class StaffSalarySettlementList extends Component
{
    public string $statusFilter = '';

    public function render()
    {
        $settlements = StaffSalarySettlement::query()
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->with('staffUser')
            ->orderByDesc('period_start')
            ->paginate(20);

        return view('livewire.admin.staff-salary-settlement-list', compact('settlements'));
    }
}
