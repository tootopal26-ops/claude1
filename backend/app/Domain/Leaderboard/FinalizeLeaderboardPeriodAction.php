<?php

namespace App\Domain\Leaderboard;

use App\Domain\Notifications\NotificationService;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
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
                    $locked->save();

                    AuditLog::record('leaderboard.finalized', null, $locked, ['type' => $locked->type]);
                }
            });
        }

        $this->payRewards($period->fresh());

        return $period->fresh();
    }

    private function payRewards(LeaderboardPeriod $period): void
    {
        $rewards = config("minimax.leaderboard_rewards.{$period->type}", []);

        if (empty($rewards)) {
            return;
        }

        $topEntries = $period->entries()->whereIn('rank', array_keys($rewards))->with('user')->get();

        foreach ($topEntries as $entry) {
            $amount = isset($rewards[$entry->rank]) ? (string) $rewards[$entry->rank] : '0.00';

            if (bccomp($amount, '0.00', 2) <= 0) {
                continue;
            }

            $alreadyPaid = LeaderboardRewardPayout::where('leaderboard_period_id', $period->id)
                ->where('user_id', $entry->user_id)
                ->exists();

            if ($alreadyPaid) {
                continue;
            }

            $this->wallet->creditWinnings(
                $entry->user,
                $amount,
                "leaderboard.reward:{$period->id}:{$entry->user_id}",
                $period,
                "Leaderboard reward ({$period->type}, rank {$entry->rank})",
            );

            try {
                LeaderboardRewardPayout::create([
                    'leaderboard_period_id' => $period->id,
                    'user_id' => $entry->user_id,
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
}
