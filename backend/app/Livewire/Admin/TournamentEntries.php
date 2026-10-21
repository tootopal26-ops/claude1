<?php

namespace App\Livewire\Admin;

use App\Domain\Tournament\ReleaseCredentialsAction;
use App\Models\Tournament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class TournamentEntries extends Component
{
    public Tournament $tournament;

    public string $roomId = '';

    public string $roomPassword = '';

    public ?string $releaseError = null;

    public function mount(Tournament $tournament): void
    {
        $this->tournament = $tournament;
    }

    public function releaseCredentials(ReleaseCredentialsAction $action): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('credentials.release'), 403);

        $this->releaseError = null;

        $this->validate([
            'roomId' => ['required', 'string', 'max:255'],
            'roomPassword' => ['required', 'string', 'max:255'],
        ]);

        try {
            $action->execute($this->tournament, $this->roomId, $this->roomPassword, Auth::guard('staff')->user());
        } catch (ValidationException $exception) {
            $this->releaseError = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->tournament->refresh();
        session()->flash('status', 'Room credentials released to joined players.');
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
