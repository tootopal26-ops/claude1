<?php

namespace App\Domain\Tournament;

use App\Models\AuditLog;
use App\Models\RecurringTournamentTemplate;
use App\Models\Tournament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates upcoming tournament occurrences from active recurring
 * templates, within each template's own rolling lookahead window
 * (generate_days_ahead). Safe to run as often as you like: the unique
 * index on tournaments(template_id, scheduled_start_at) makes a repeat
 * run for an occurrence that already exists a no-op, and a start time
 * that has already passed is never generated in the first place.
 *
 * A generated occurrence that would land on the same game at the same
 * scheduled_start_at as an existing, non-cancelled tournament from a
 * DIFFERENT source (another template, or one staff created by hand) is
 * skipped and logged as a conflict for staff to review, rather than
 * silently double-booking that time slot.
 */
class GenerateTournamentsFromTemplatesAction
{
    /**
     * @return array{generated: int, skipped_conflicts: int}
     */
    public function execute(?RecurringTournamentTemplate $only = null): array
    {
        // Re-fetch rather than trust $only's in-memory attributes: a model
        // just created in the same request never had its DB-level column
        // defaults (e.g. per_kill_reward) loaded back into memory.
        $templates = $only !== null
            ? collect([RecurringTournamentTemplate::findOrFail($only->id)])
            : RecurringTournamentTemplate::where('is_active', true)->get();

        $generated = 0;
        $skippedConflicts = 0;

        foreach ($templates as $template) {
            foreach ($this->applicableStartTimes($template) as $scheduledStartAt) {
                $outcome = $this->generateOne($template, $scheduledStartAt);

                if ($outcome === 'generated') {
                    $generated++;
                } elseif ($outcome === 'conflict') {
                    $skippedConflicts++;
                }
            }
        }

        return ['generated' => $generated, 'skipped_conflicts' => $skippedConflicts];
    }

    /**
     * @return list<Carbon>
     */
    private function applicableStartTimes(RecurringTournamentTemplate $template): array
    {
        $times = [];
        $today = today();

        for ($offset = 0; $offset <= $template->generate_days_ahead; $offset++) {
            $date = $today->copy()->addDays($offset);

            if ($template->frequency === 'weekly' && $date->dayOfWeek !== $template->day_of_week) {
                continue;
            }

            $startAt = Carbon::parse($date->toDateString().' '.$template->time_of_day);

            if ($startAt->isFuture()) {
                $times[] = $startAt;
            }
        }

        return $times;
    }

    private function generateOne(RecurringTournamentTemplate $template, Carbon $scheduledStartAt): string
    {
        $conflict = Tournament::query()
            ->where('game_id', $template->game_id)
            ->where('scheduled_start_at', $scheduledStartAt)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($template) {
                $query->whereNull('template_id')->orWhere('template_id', '!=', $template->id);
            })
            ->exists();

        if ($conflict) {
            AuditLog::record('tournament.generation_conflict', null, $template, [
                'scheduled_start_at' => $scheduledStartAt->toIso8601String(),
            ]);

            return 'conflict';
        }

        try {
            $tournament = DB::transaction(fn () => Tournament::create([
                'game_id' => $template->game_id,
                'title' => $template->title,
                'description' => $template->description,
                'rules' => $template->rules,
                'mode' => $template->mode,
                'entry_fee' => $template->entry_fee,
                'per_kill_reward' => $template->per_kill_reward,
                'prize_pool' => $template->prize_pool,
                'total_slots' => $template->total_slots,
                'min_players' => $template->min_players,
                'scheduled_start_at' => $scheduledStartAt,
                'status' => 'upcoming',
                'template_id' => $template->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            return 'already_exists';
        }

        AuditLog::record('tournament.generated_from_template', null, $tournament, ['template_id' => $template->id]);

        return 'generated';
    }
}
