<?php

namespace App\Livewire\Admin;

use App\Models\Tournament;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class TournamentList extends Component
{
    use WithPagination;

    public function render()
    {
        $tournaments = Tournament::with('game')
            ->withCount(['entries as joined_count' => fn ($query) => $query->where('status', 'confirmed')])
            ->orderByDesc('scheduled_start_at')
            ->paginate(15);

        return view('livewire.admin.tournament-list', compact('tournaments'));
    }
}
