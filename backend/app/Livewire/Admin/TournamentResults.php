<?php

namespace App\Livewire\Admin;

use App\Domain\Tournament\SubmitTournamentResultsAction;
use App\Models\Tournament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class TournamentResults extends Component
{
    public Tournament $tournament;

    /**
     * @var array<int, array{tournament_entry_id: int, username: string, rank: int, kills: int, prize_awarded: string}>
     */
    public array $rows = [];

    public ?string $submitError = null;

    public function mount(Tournament $tournament): void
    {
        $this->tournament = $tournament;

        $entries = $tournament->entries()->where('status', 'confirmed')->with(['user', 'results'])->orderBy('created_at')->get();

        $this->rows = $entries->values()->map(function ($entry, $index) {
            $current = $entry->currentResult();

            return [
                'tournament_entry_id' => $entry->id,
                'username' => $entry->user->username,
                'rank' => $current?->rank ?? $index + 1,
                'kills' => $current?->kills ?? 0,
                'prize_awarded' => $current ? (string) $current->prize_awarded : '0.00',
            ];
        })->all();
    }

    public function submit(SubmitTournamentResultsAction $action): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('results.submit'), 403);

        $this->submitError = null;

        $this->validate([
            'rows.*.rank' => ['required', 'integer', 'min:1'],
            'rows.*.kills' => ['required', 'integer', 'min:0'],
            'rows.*.prize_awarded' => ['required', 'numeric', 'min:0'],
        ]);

        $results = collect($this->rows)->map(fn ($row) => [
            'tournament_entry_id' => $row['tournament_entry_id'],
            'rank' => (int) $row['rank'],
            'kills' => (int) $row['kills'],
            'prize_awarded' => (string) $row['prize_awarded'],
        ])->all();

        try {
            $revisionNumber = $action->execute($this->tournament, $results, Auth::guard('staff')->user());
        } catch (ValidationException $exception) {
            $this->submitError = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->tournament->refresh();
        session()->flash('status', $revisionNumber === 1 ? 'Results published.' : 'Results revised.');
    }

    public function render()
    {
        return view('livewire.admin.tournament-results');
    }
}
