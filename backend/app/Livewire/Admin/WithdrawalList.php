<?php

namespace App\Livewire\Admin;

use App\Models\WithdrawalRequest;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class WithdrawalList extends Component
{
    public string $statusFilter = '';

    public function render()
    {
        $withdrawals = WithdrawalRequest::query()
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.withdrawal-list', compact('withdrawals'));
    }
}
