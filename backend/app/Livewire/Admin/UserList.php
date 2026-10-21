<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class UserList extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $users = User::query()
            ->with('wallet')
            ->when($this->search !== '', fn ($query) => $query
                ->where('username', 'like', "%{$this->search}%")
                ->orWhere('mobile', 'like', "%{$this->search}%"))
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.user-list', compact('users'));
    }
}
