<?php

namespace App\Livewire\Admin;

use App\Domain\Leaderboard\FinalizeLeaderboardPeriodAction;
use App\Models\AuditLog;
use App\Models\LeaderboardPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Shows what every finalized period was supposed to pay against what it did pay,
 * so a payout that is pending or withheld is visible and can be retried safely
 * (a retry only completes missing payouts; a payout is never made twice).
 */
#[Layout('components.layouts.admin')]
class LeaderboardSettlement extends Component
{
    public function retry(int $periodId, FinalizeLeaderboardPeriodAction $finalize): void
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('leaderboards.manage'), 403);

        $period = LeaderboardPeriod::where('status', 'finalized')->findOrFail($periodId);
        $pendingBefore = $this->summarize($period, $finalize)['pending']->count();

        $finalize->payRewards($period);

        $pendingAfter = $this->summarize($period->fresh(), $finalize)['pending']->count();

        AuditLog::record('leaderboard.payouts_retried', $staff, $period, [
            'type' => $period->type,
            'pending_before' => $pendingBefore,
            'pending_after' => $pendingAfter,
        ]);

        session()->flash('status', 'Retried '.$pendingBefore.' pending payout(s): '.($pendingBefore - $pendingAfter).' paid, '.$pendingAfter.' still pending.');
    }

    /**
     * @return array{period: LeaderboardPeriod, prizes: array<int, int>, expected_coins: int, paid_count: int, paid_coins: int, pending: Collection<int, array{rank: int, username: string, coins: int, reason: string}>}
     */
    private function summarize(LeaderboardPeriod $period, FinalizeLeaderboardPeriodAction $finalize): array
    {
        $prizes = $finalize->prizeTable($period);
        $entries = $period->entries()->whereIn('rank', array_keys($prizes) ?: [0])->with('user')->orderBy('rank')->get();
        $payouts = $period->rewardPayouts()->get()->keyBy('user_id');

        $pending = $entries
            ->reject(fn ($entry) => $payouts->has($entry->user_id))
            ->map(fn ($entry) => [
                'rank' => $entry->rank,
                'username' => $entry->user->username,
                'coins' => $prizes[$entry->rank] ?? 0,
                'reason' => $entry->user->is_banned ? 'Withheld — account is banned' : 'Not paid yet',
            ])
            ->values();

        return [
            'period' => $period,
            'prizes' => $prizes,
            'expected_coins' => (int) $entries->sum(fn ($entry) => $prizes[$entry->rank] ?? 0),
            'paid_count' => $payouts->count(),
            'paid_coins' => (int) $payouts->sum('amount'),
            'pending' => $pending,
        ];
    }

    public function render(FinalizeLeaderboardPeriodAction $finalize)
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('leaderboards.manage'), 403);

        $rows = LeaderboardPeriod::where('status', 'finalized')
            ->orderByDesc('finalized_at')
            ->limit(15)
            ->get()
            ->map(fn (LeaderboardPeriod $period) => $this->summarize($period, $finalize));

        return view('livewire.admin.leaderboard-settlement', ['rows' => $rows]);
    }
}
