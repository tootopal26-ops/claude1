<?php

namespace App\Livewire\Admin;

use App\Models\DepositOrder;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class DepositList extends Component
{
    public string $statusFilter = '';

    public function render()
    {
        $deposits = DepositOrder::query()
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.deposit-list', compact('deposits'));
    }
}
