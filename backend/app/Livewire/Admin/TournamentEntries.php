<?php

namespace App\Livewire\Admin;

use App\Models\Tournament;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class TournamentEntries extends Component
{
    public Tournament $tournament;

    public function mount(Tournament $tournament): void
    {
        $this->tournament = $tournament;
    }

    public function render()
    {
        $entries = $this->tournament->entries()
            ->with(['user', 'players'])
            ->where('status', 'confirmed')
            ->orderBy('created_at')
            ->get();

        return view('livewire.admin.tournament-entries', compact('entries'));
    }
}
