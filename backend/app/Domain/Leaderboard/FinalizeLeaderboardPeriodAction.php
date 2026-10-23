<?php

namespace App\Domain\Leaderboard;

use App\Domain\Notifications\NotificationService;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\LeaderboardEntry;
use App\Models\LeaderboardPeriod;
use App\Models\LeaderboardRewardPayout;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Locks in a period's final standings (one last recompute, then marks it
 * finalized) and pays configured top-rank rewards exactly once. Safe to
 * call again on an already-finalized period — entries stay locked, and
 * payRewards() simply catches up anything a prior interrupted run didn't
 * finish, since every step here (the wallet credit, the payout record,
 * the notification) is independently idempotent.
 */
class FinalizeLeaderboardPeriodAction
{
    public function __construct(
        private readonly RecomputeLeaderboardAction $recompute,
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
        private readonly LeaderboardPrizeService $prizes,
    ) {}

    public function execute(LeaderboardPeriod $period): LeaderboardPeriod
    {
        if (! $period->isFinalized()) {
            $this->recompute->execute($period);

            DB::transaction(function () use ($period) {
                /** @var LeaderboardPeriod $locked */
                $locked = LeaderboardPeriod::query()->lockForUpdate()->find($period->id);

                if (! $locked->isFinalized()) {
                    $locked->status = 'finalized';
                    $locked->finalized_at = now();
                    // Freeze what this period pays at the instant it closes: later prize edits
                    // never reach a finalized period, and a repeat run can't pay new ranks.
                    $locked->prize_snapshot = $this->prizes->snapshotFor($locked->type);
                    $locked->save();

                    AuditLog::record('leaderboard.finalized', null, $locked, [
                        'type' => $locked->type,
                        'prize_ranks' => count($locked->prize_snapshot),
                    ]);
                }
            });
        }

        $this->payRewards($period->fresh());

        return $period->fresh();
    }

    /**
     * Pays every prized rank of a finalized period exactly once. Each payout is
     * independent: one that fails is reported and left pending (the settlement
     * page shows it and can retry), and never stops the others.
     */
    public function payRewards(LeaderboardPeriod $period): void
    {
        $rewards = $this->prizeTable($period);

        if (empty($rewards)) {
            return;
        }

        $topEntries = $period->entries()->whereIn('rank', array_keys($rewards))->with('user')->get();

        foreach ($topEntries as $entry) {
            try {
                $this->payEntry($period, $entry, $rewards[$entry->rank] ?? 0);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * rank => coins for a period: the snapshot frozen at finalization, or, for
     * periods finalized before snapshots existed, the original config values.
     *
     * @return array<int, int>
     */
    public function prizeTable(LeaderboardPeriod $period): array
    {
        if ($period->prize_snapshot !== null) {
            return array_map('intval', $period->prize_snapshot);
        }

        return collect((array) config("minimax.leaderboard_rewards.{$period->type}", []))
            ->map(fn ($amount) => (int) round((float) $amount))
            ->filter(fn (int $coins) => $coins > 0)
            ->all();
    }

    private function payEntry(LeaderboardPeriod $period, LeaderboardEntry $entry, int $coins): void
    {
        if ($coins <= 0) {
            return;
        }

        // A banned account is not paid. The payout stays pending (the settlement page lists it
        // as withheld) and is paid by a retry if the ban is lifted.
        if ($entry->user->is_banned) {
            return;
        }

        $amount = number_format($coins, 2, '.', '');

        $alreadyPaid = LeaderboardRewardPayout::where('leaderboard_period_id', $period->id)
            ->where('user_id', $entry->user_id)
            ->exists();

        if ($alreadyPaid) {
            return;
        }

        $this->wallet->creditWinnings(
            $entry->user,
            $amount,
            "leaderboard.reward:{$period->id}:{$entry->user_id}",
            $period,
            "Leaderboard reward ({$period->type}, rank {$entry->rank})",
        );

        try {
            $payout = LeaderboardRewardPayout::create([
                'leaderboard_period_id' => $period->id,
                'user_id' => $entry->user_id,
                'rank' => $entry->rank,
                'amount' => $amount,
            ]);

            AuditLog::record('leaderboard.reward_paid', null, $payout, [
                'leaderboard_period_id' => $period->id,
                'type' => $period->type,
                'rank' => $entry->rank,
                'amount' => $amount,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent run already recorded this payout; the credit
            // above was itself idempotent via its own key, so nothing
            // was double-paid.
        }

        $this->notifications->notify(
            recipients: [$entry->user],
            type: 'leaderboard.reward',
            title: 'Leaderboard reward!',
            body: "You finished #{$entry->rank} on the {$period->type} leaderboard and earned {$amount} coins!",
            data: ['leaderboard_period_id' => (string) $period->id],
            idempotencyKey: "leaderboard.reward_notify:{$period->id}:{$entry->user_id}",
        );
    }
}
