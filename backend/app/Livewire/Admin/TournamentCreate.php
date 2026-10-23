<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Tournament;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class TournamentCreate extends Component
{
    public ?int $game_id = null;

    public string $title = '';

    public string $description = '';

    public string $mode = 'solo';

    public float $entry_fee = 0;

    public float $per_kill_reward = 0;

    public float $prize_pool = 0;

    public int $total_slots = 48;

    public ?int $min_players = null;

    public string $scheduled_start_at = '';

    public function mount(): void
    {
        $this->scheduled_start_at = now()->addDay()->format('Y-m-d\TH:i');
    }

    /**
     * Friendly names for validation messages ("The game field is required").
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'game_id' => 'game',
            'total_slots' => 'total slots',
            'min_players' => 'minimum teams',
            'entry_fee' => 'entry fee',
            'per_kill_reward' => 'per-kill reward',
            'prize_pool' => 'prize pool',
            'scheduled_start_at' => 'start time',
        ];
    }

    public function save(): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('tournaments.manage'), 403);

        $validated = $this->validate([
            'game_id' => ['required', 'exists:games,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'mode' => ['required', 'in:solo,duo,squad'],
            'entry_fee' => ['required', 'numeric', 'min:0'],
            'per_kill_reward' => ['required', 'numeric', 'min:0'],
            'prize_pool' => ['required', 'numeric', 'min:0'],
            'total_slots' => ['required', 'integer', 'min:1', 'max:200'],
            'min_players' => ['nullable', 'integer', 'min:1', 'lte:total_slots'],
            'scheduled_start_at' => ['required', 'date', 'after:now'],
        ]);

        $tournament = Tournament::create([
            ...$validated,
            'status' => 'upcoming',
        ]);

        AuditLog::record('tournament.created', Auth::guard('staff')->user(), $tournament, [
            'title' => $tournament->title,
        ]);

        session()->flash('status', "Tournament \"{$tournament->title}\" created.");

        $this->redirectRoute(Portal::routeName('tournaments.index'), navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.tournament-create', [
            'games' => Game::where('is_active', true)->orderBy('display_order')->get(),
        ]);
    }
}
