<?php

namespace App\Livewire\Admin;

use App\Domain\Withdrawal\WithdrawalService;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class WithdrawalShow extends Component
{
    public WithdrawalRequest $withdrawal;

    public string $rejectionReason = '';

    public string $settlementReference = '';

    public string $failureReason = '';

    public ?string $actionError = null;

    public function mount(WithdrawalRequest $withdrawal): void
    {
        $this->withdrawal = $withdrawal;
    }

    public function approve(WithdrawalService $service): void
    {
        $this->perform(fn () => $service->approve($this->withdrawal, $this->actor()), 'Withdrawal approved.');
    }

    public function reject(WithdrawalService $service): void
    {
        $this->actionError = null;
        $this->validate(['rejectionReason' => ['required', 'string', 'max:255']]);

        $this->perform(fn () => $service->reject($this->withdrawal, $this->rejectionReason, $this->actor()), 'Withdrawal rejected and the amount returned to the player.');
    }

    public function markProcessing(WithdrawalService $service): void
    {
        $this->perform(fn () => $service->markProcessing($this->withdrawal, $this->actor()), 'Marked as processing.');
    }

    public function markPaid(WithdrawalService $service): void
    {
        $this->actionError = null;
        $this->validate(['settlementReference' => ['required', 'string', 'max:255']]);

        $this->perform(fn () => $service->markPaid($this->withdrawal, $this->settlementReference, $this->actor()), 'Withdrawal marked as paid.');
    }

    public function markFailed(WithdrawalService $service): void
    {
        $this->actionError = null;
        $this->validate(['failureReason' => ['required', 'string', 'max:255']]);

        $this->perform(fn () => $service->markFailed($this->withdrawal, $this->failureReason, $this->actor()), 'Withdrawal marked as failed and the amount returned to the player.');
    }

    public function render()
    {
        return view('livewire.admin.withdrawal-show');
    }

    private function actor()
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('withdrawals.manage'), 403);

        return Auth::guard('staff')->user();
    }

    private function perform(callable $action, string $successMessage): void
    {
        $this->actionError = null;

        try {
            $action();
        } catch (ValidationException $exception) {
            $this->actionError = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->withdrawal->refresh();
        session()->flash('status', $successMessage);
    }
}
