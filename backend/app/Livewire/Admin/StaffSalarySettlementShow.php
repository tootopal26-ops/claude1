<?php

namespace App\Livewire\Admin;

use App\Domain\StaffSalary\MarkStaffSalarySettlementPaidAction;
use App\Models\StaffSalarySettlement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class StaffSalarySettlementShow extends Component
{
    public StaffSalarySettlement $settlement;

    public string $paymentReference = '';

    public ?string $actionError = null;

    public function mount(StaffSalarySettlement $settlement): void
    {
        $this->settlement = $settlement;
    }

    public function markPaid(MarkStaffSalarySettlementPaidAction $action): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('salary.approve'), 403);

        $this->actionError = null;
        $this->validate(['paymentReference' => ['required', 'string', 'max:255']]);

        try {
            $action->execute($this->settlement, $this->paymentReference, Auth::guard('staff')->user());
        } catch (ValidationException $exception) {
            $this->actionError = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->settlement->refresh();
        session()->flash('status', 'Settlement marked as paid.');
    }

    public function render()
    {
        $items = $this->settlement->items()->with('event')->get();

        return view('livewire.admin.staff-salary-settlement-show', compact('items'));
    }
}
