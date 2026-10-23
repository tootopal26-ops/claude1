<?php

namespace App\Http\Resources;

use App\Models\LeaderboardEntry;
use App\Models\LeaderboardPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaderboardPeriod */
class LeaderboardResource extends JsonResource
{
    /**
     * @param  Collection<int, LeaderboardEntry>  $topEntries
     * @param  array<int, int>  $prizeTable  rank => coins (ranks without a prize are absent)
     */
    public function __construct(LeaderboardPeriod $period, private readonly Collection $topEntries, private readonly ?LeaderboardEntry $myEntry, private readonly array $prizeTable = [])
    {
        parent::__construct($period);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'period_start' => $this->period_start->toIso8601String(),
            'period_end' => $this->period_end->toIso8601String(),
            'status' => $this->status,
            'entries' => $this->topEntries->map(fn (LeaderboardEntry $entry) => $this->formatEntry($entry))->all(),
            'my_entry' => $this->myEntry ? $this->formatEntry($this->myEntry) : null,
            'prizes_final' => $this->isFinalized(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(LeaderboardEntry $entry): array
    {
        return [
            'rank' => $entry->rank,
            'user_id' => $entry->user_id,
            'username' => $entry->user->username,
            // Total prize winnings in the period (the ranking key).
            'score' => (string) $entry->score,
            'prize' => $this->prizeTable[$entry->rank] ?? null,
            'total_kills' => $entry->total_kills,
            'tournaments_played' => $entry->tournaments_played,
        ];
    }
}
