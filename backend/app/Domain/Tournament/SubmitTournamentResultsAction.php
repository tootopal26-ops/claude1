<?php

namespace App\Domain\Tournament;

use App\Domain\Notifications\NotificationService;
use App\Domain\Wallet\InsufficientWalletBalanceException;
use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\TournamentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes or revises a tournament's results, one call per tournament
 * covering every confirmed entry at once. The tournament's current
 * revision number (the max revision_number across its result rows, 0 if
 * none yet) always advances by exactly one per call, uniformly across
 * every entry — there's no such thing as revising only some entries.
 *
 * Every entry's prize is applied to its wallet as a signed delta against
 * whatever it was previously credited (0 on first publish), via
 * WalletService::adjustWinningsBalance, so a correction only ever moves
 * the difference rather than double-crediting the full new amount. If a
 * reduction can't be applied because the player has since spent or
 * withdrawn those winnings, the whole submission is rejected rather than
 * partially applied — the prior result stays in force until finance
 * resolves it and the correction is retried.
 */
class SubmitTournamentResultsAction
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  list<array{tournament_entry_id: int, rank: int, kills: int, prize_awarded: string}>  $results
     *
     * @throws ValidationException
     */
    public function execute(Tournament $tournament, array $results, StaffUser $actor): int
    {
        $newRevisionNumber = DB::transaction(function () use ($tournament, $results, $actor) {
            /** @var Tournament $locked */
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if (! in_array($locked->status, ['ongoing', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'tournament' => 'Results can only be submitted for an ongoing or completed tournament.',
                ]);
            }

            $confirmedEntryIds = $locked->entries()->where('status', 'confirmed')->pluck('id');
            $submittedEntryIds = collect($results)->pluck('tournament_entry_id');

            if ($submittedEntryIds->unique()->count() !== $submittedEntryIds->count()) {
                throw ValidationException::withMessages([
                    'results' => 'The same entry cannot appear twice in one results submission.',
                ]);
            }

            if ($confirmedEntryIds->sort()->values()->toArray() !== $submittedEntryIds->sort()->values()->toArray()) {
                throw ValidationException::withMessages([
                    'results' => 'Results must cover exactly every confirmed entry, no more and no fewer.',
                ]);
            }

            $totalPrize = collect($results)->reduce(fn ($carry, $r) => bcadd($carry, $r['prize_awarded'], 2), '0.00');

            if (bccomp($totalPrize, (string) $locked->prize_pool, 2) > 0) {
                throw ValidationException::withMessages([
                    'results' => "Total prize awarded ({$totalPrize}) exceeds this tournament's prize pool ({$locked->prize_pool}).",
                ]);
            }

            foreach ($results as $result) {
                $this->assertValidShape($result);
            }

            $previousRevisionNumber = (int) TournamentResult::where('tournament_id', $locked->id)->max('revision_number');
            $newRevisionNumber = $previousRevisionNumber + 1;

            $previousRows = $previousRevisionNumber > 0
                ? TournamentResult::where('tournament_id', $locked->id)
                    ->where('revision_number', $previousRevisionNumber)
                    ->get()
                    ->keyBy('tournament_entry_id')
                : collect();

            foreach ($results as $result) {
                $entry = $locked->entries()->with('user')->findOrFail($result['tournament_entry_id']);
                $previousRow = $previousRows->get($entry->id);
                $previousPrize = $previousRow?->prize_awarded ?? '0.00';

                $newRow = TournamentResult::create([
                    'tournament_id' => $locked->id,
                    'tournament_entry_id' => $entry->id,
                    'revision_number' => $newRevisionNumber,
                    'rank' => $result['rank'],
                    'kills' => $result['kills'],
                    'prize_awarded' => $result['prize_awarded'],
                    'submitted_by_staff_id' => $actor->id,
                ]);

                if ($previousRow !== null) {
                    $previousRow->superseded_by_id = $newRow->id;
                    $previousRow->save();
                }

                $delta = bcsub((string) $result['prize_awarded'], (string) $previousPrize, 2);

                try {
                    $this->wallet->adjustWinningsBalance(
                        $entry->user,
                        $delta,
                        'result_correction',
                        "tournament.prize:{$entry->id}:{$newRevisionNumber}",
                        $newRow,
                        $newRevisionNumber === 1 ? 'Tournament prize' : 'Tournament prize correction',
                    );
                } catch (InsufficientWalletBalanceException $exception) {
                    throw ValidationException::withMessages([
                        'results' => "Cannot reduce {$entry->user->username}'s prize: {$exception->getMessage()}",
                    ]);
                }
            }

            $locked->status = 'completed';
            $locked->save();

            AuditLog::record(
                $newRevisionNumber === 1 ? 'tournament.results_published' : 'tournament.results_revised',
                $actor,
                $locked,
                ['revision_number' => $newRevisionNumber, 'entries' => count($results)],
            );

            return $newRevisionNumber;
        });

        $this->notifyParticipants($tournament, $newRevisionNumber);

        return $newRevisionNumber;
    }

    /**
     * @param  array{tournament_entry_id: int, rank: int, kills: int, prize_awarded: string}  $result
     */
    private function assertValidShape(array $result): void
    {
        if ($result['rank'] < 1 || $result['kills'] < 0 || bccomp((string) $result['prize_awarded'], '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                'results' => 'Rank must be at least 1, kills and prize awarded cannot be negative.',
            ]);
        }
    }

    private function notifyParticipants(Tournament $tournament, int $revisionNumber): void
    {
        $isFirstPublish = $revisionNumber === 1;

        foreach ($tournament->entries()->where('status', 'confirmed')->with('user')->get() as $entry) {
            $result = $entry->currentResult();

            if ($result === null) {
                continue;
            }

            $prizeLine = bccomp((string) $result->prize_awarded, '0.00', 2) > 0
                ? " You won {$result->prize_awarded} coins!"
                : '';

            $this->notifications->notify(
                recipients: [$entry->user],
                type: $isFirstPublish ? 'tournament.result_published' : 'tournament.result_revised',
                title: $isFirstPublish ? 'Results are in!' : 'Your result was updated',
                body: ($isFirstPublish
                    ? "You placed #{$result->rank} in {$tournament->title}."
                    : "Your result in {$tournament->title} was corrected: #{$result->rank}.")
                    .$prizeLine,
                data: ['tournament_id' => (string) $tournament->id, 'deep_link' => "tournament/{$tournament->id}"],
                idempotencyKey: "tournament.result_notified:{$entry->id}:{$revisionNumber}",
            );
        }
    }
}
