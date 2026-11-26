<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Support\MobileNumber;
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
        $term = trim($this->search);
        // Only when the term, once stripped of "+91"/spaces/punctuation, is a
        // complete 10-digit number — so a search for ordinary digits that
        // isn't a full phone number doesn't trigger extra, surprising matches.
        $normalizedMobile = MobileNumber::isValid($term) ? MobileNumber::normalize($term) : null;

        $users = User::query()
            ->with('wallet')
            ->when($term !== '', function ($query) use ($term, $normalizedMobile) {
                $query->where(function ($q) use ($term, $normalizedMobile) {
                    $q->where('username', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('mobile', 'like', "%{$term}%");

                    if ($normalizedMobile !== null) {
                        $q->orWhere('mobile', 'like', "%{$normalizedMobile}%");
                    }
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.admin.user-list', compact('users'));
    }
}
