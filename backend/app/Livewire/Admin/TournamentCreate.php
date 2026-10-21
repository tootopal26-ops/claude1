<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Tournament;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class TournamentCreate extends Component
{
    public ?int $game_id = null;

    public string $title = '';

    public string $description = '';

    public float $entry_fee = 0;

    public int $total_slots = 48;

    public string $scheduled_start_at = '';

    public function mount(): void
    {
        $this->scheduled_start_at = now()->addDay()->format('Y-m-d\TH:i');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'game_id' => ['required', 'exists:games,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'entry_fee' => ['required', 'numeric', 'min:0'],
            'total_slots' => ['required', 'integer', 'min:1', 'max:200'],
            'scheduled_start_at' => ['required', 'date', 'after:now'],
        ]);

        $tournament = Tournament::create([
            ...$validated,
            'mode' => 'solo',
            'status' => 'upcoming',
        ]);

        AuditLog::record('tournament.created', Auth::guard('staff')->user(), $tournament, [
            'title' => $tournament->title,
        ]);

        session()->flash('status', "Tournament \"{$tournament->title}\" created.");

        $this->redirectRoute('admin.tournaments.index', navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.tournament-create', [
            'games' => Game::where('is_active', true)->orderBy('display_order')->get(),
        ]);
    }
}
