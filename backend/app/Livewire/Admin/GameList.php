<?php

namespace App\Livewire\Admin;

use App\Domain\Games\GameCardService;
use App\Models\Game;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class GameList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function toggle(int $gameId, GameCardService $cards): void
    {
        $game = Game::findOrFail($gameId);
        $cards->setActive($game, ! $game->is_active, $this->actor());

        session()->flash('status', "\"{$game->name}\" is now ".($game->is_active ? 'visible in the app.' : 'hidden from the app.'));
    }

    public function moveUp(int $gameId, GameCardService $cards): void
    {
        $cards->move(Game::findOrFail($gameId), -1, $this->actor());
    }

    public function moveDown(int $gameId, GameCardService $cards): void
    {
        $cards->move(Game::findOrFail($gameId), 1, $this->actor());
    }

    public function delete(int $gameId, GameCardService $cards): void
    {
        $game = Game::findOrFail($gameId);
        $name = $game->name;

        $cards->delete($game, $this->actor());

        session()->flash('status', "\"{$name}\" was deleted.");
    }

    private function actor()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('games.manage'), 403);

        return $staff;
    }

    public function render()
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('games.manage'), 403);

        $games = Game::query()
            ->with('thumbnailAsset')
            ->withCount('tournaments')
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate(10);

        return view('livewire.admin.game-list', ['games' => $games, 'total' => Game::count()]);
    }
}
