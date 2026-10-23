<?php

namespace App\Domain\Leaderboard;

use App\Models\AuditLog;
use App\Models\LeaderboardPrizeRank;
use App\Models\LeaderboardPrizeRule;
use App\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the admin-editable prizes for each leaderboard period type.
 * Saving prizes never pays anyone: payouts happen only when a period finalizes
 * (FinalizeLeaderboardPeriodAction), from the snapshot taken at that moment.
 */
class LeaderboardPrizeService
{
    public const TYPES = ['daily', 'weekly', 'monthly'];

    public const MAX_COINS = 10_000_000;

    public function rule(string $type): LeaderboardPrizeRule
    {
        return LeaderboardPrizeRule::firstOrCreate(['period_type' => $type]);
    }

    /**
     * The editor's starting values for one period type.
     *
     * @return array{enabled: bool, first: int, second: int, third: int, rest: int, restIsUniform: bool}
     */
    public function draft(string $type): array
    {
        $rule = $this->rule($type);
        $coins = $rule->coinsByRank();
        $rest = collect(range(4, LeaderboardPrizeRule::MAX_RANK))->map(fn (int $rank) => $coins[$rank] ?? 0);

        return [
            'enabled' => $rule->is_enabled,
            'first' => $coins[1] ?? 0,
            'second' => $coins[2] ?? 0,
            'third' => $coins[3] ?? 0,
            'rest' => (int) $rest->first(),
            'restIsUniform' => $rest->unique()->count() === 1,
        ];
    }

    /**
     * Expands the editor's four amounts into the full rank => coins table
     * (ranks 1 to 100). Ranks that pay nothing are included as 0 so a preview
     * can show every rank.
     *
     * @return array<int, int>
     */
    public function expand(int $first, int $second, int $third, int $rest): array
    {
        $table = [1 => $first, 2 => $second, 3 => $third];

        for ($rank = 4; $rank <= LeaderboardPrizeRule::MAX_RANK; $rank++) {
            $table[$rank] = $rest;
        }

        return $table;
    }

    public function save(string $type, bool $enabled, int $first, int $second, int $third, int $rest, StaffUser $actor): LeaderboardPrizeRule
    {
        $before = $this->draft($type);

        $rule = DB::transaction(function () use ($type, $enabled, $first, $second, $third, $rest, $actor) {
            $rule = LeaderboardPrizeRule::query()->lockForUpdate()->firstOrCreate(['period_type' => $type]);

            $rule->forceFill(['is_enabled' => $enabled, 'updated_by_staff_id' => $actor->id])->save();

            LeaderboardPrizeRank::where('leaderboard_prize_rule_id', $rule->id)->delete();

            $rows = [];
            $now = now();

            foreach ($this->expand($first, $second, $third, $rest) as $rank => $coins) {
                if ($coins > 0) {
                    $rows[] = [
                        'leaderboard_prize_rule_id' => $rule->id,
                        'rank' => $rank,
                        'coins' => $coins,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            LeaderboardPrizeRank::insert($rows);

            return $rule;
        });

        AuditLog::record('leaderboard.prizes_updated', $actor, $rule, [
            'period_type' => $type,
            'before' => $before,
            'after' => ['enabled' => $enabled, 'first' => $first, 'second' => $second, 'third' => $third, 'rest' => $rest],
        ]);

        return $rule;
    }

    /**
     * The rank => coins table a period freezes onto itself when it finalizes.
     * A disabled period type freezes an empty table, so it pays nothing.
     *
     * @return array<int, int>
     */
    public function snapshotFor(string $type): array
    {
        $rule = LeaderboardPrizeRule::where('period_type', $type)->first();

        if ($rule === null) {
            // No rule yet (never migrated): behave as the platform always has.
            return collect((array) config("minimax.leaderboard_rewards.{$type}", []))
                ->map(fn ($amount) => (int) round((float) $amount))
                ->filter(fn (int $coins) => $coins > 0)
                ->all();
        }

        return $rule->is_enabled ? $rule->coinsByRank() : [];
    }
}
