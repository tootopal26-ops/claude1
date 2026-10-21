<?php

namespace App\Livewire\Admin;

use App\Domain\Leaderboard\EnsureLeaderboardPeriodsExistAction;
use App\Domain\Leaderboard\FinalizeLeaderboardPeriodAction;
use App\Domain\Leaderboard\RecomputeLeaderboardAction;
use App\Models\LeaderboardPeriod;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class LeaderboardViewer extends Component
{
    public string $type = 'daily';

    public ?string $actionStatus = null;

    public function recomputeNow(RecomputeLeaderboardAction $recompute): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('leaderboards.manage'), 403);

        $recompute->execute($this->currentPeriod());
        $this->actionStatus = 'Recomputed.';
    }

    public function finalizeNow(FinalizeLeaderboardPeriodAction $finalize): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('leaderboards.manage'), 403);

        $finalize->execute($this->currentPeriod());
        $this->actionStatus = 'Finalized and rewards paid.';
    }

    private function currentPeriod(): LeaderboardPeriod
    {
        return LeaderboardPeriod::where('type', $this->type)->orderByDesc('period_start')->firstOrFail();
    }

    public function render(EnsureLeaderboardPeriodsExistAction $ensure)
    {
        $period = LeaderboardPeriod::where('type', $this->type)->orderByDesc('period_start')->first()
            ?? $ensure->execute()[$this->type];

        $entries = $period->entries()->with('user')->orderBy('rank')->get();

        return view('livewire.admin.leaderboard-viewer', compact('period', 'entries'));
    }
}
