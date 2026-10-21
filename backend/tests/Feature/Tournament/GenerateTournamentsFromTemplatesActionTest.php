<?php

namespace Tests\Feature\Tournament;

use App\Domain\Tournament\GenerateTournamentsFromTemplatesAction;
use App\Models\Game;
use App\Models\RecurringTournamentTemplate;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GenerateTournamentsFromTemplatesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeTemplate(array $overrides = []): RecurringTournamentTemplate
    {
        return RecurringTournamentTemplate::create(array_merge([
            'game_id' => Game::factory()->create()->id,
            'title' => 'Daily Solo Cup',
            'mode' => 'solo',
            'entry_fee' => 0,
            'total_slots' => 48,
            'frequency' => 'daily',
            'time_of_day' => '10:00',
            'generate_days_ahead' => 3,
            'is_active' => true,
        ], $overrides));
    }

    public function test_a_daily_template_generates_one_occurrence_per_day_in_the_window(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $template = $this->makeTemplate(['generate_days_ahead' => 3]);

        $result = app(GenerateTournamentsFromTemplatesAction::class)->execute();

        $this->assertSame(4, $result['generated']); // today + 3 days ahead, all still in the future
        $this->assertSame(4, Tournament::where('template_id', $template->id)->count());
    }

    public function test_a_weekly_template_only_generates_on_the_matching_day_of_week(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00'); // a Thursday
        $template = $this->makeTemplate([
            'frequency' => 'weekly',
            'day_of_week' => 6, // Saturday
            'generate_days_ahead' => 10,
        ]);

        app(GenerateTournamentsFromTemplatesAction::class)->execute();

        $dates = Tournament::where('template_id', $template->id)->pluck('scheduled_start_at')
            ->map(fn ($d) => $d->toDateString())->sort()->values();

        $this->assertSame(['2026-01-03', '2026-01-10'], $dates->all());
    }

    public function test_an_occurrence_whose_time_has_already_passed_today_is_not_generated(): void
    {
        Carbon::setTestNow('2026-01-01 15:00:00');
        $template = $this->makeTemplate(['time_of_day' => '10:00', 'generate_days_ahead' => 2]);

        $result = app(GenerateTournamentsFromTemplatesAction::class)->execute();

        $this->assertSame(2, $result['generated']); // only tomorrow + the day after, not today
        $this->assertDatabaseMissing('tournaments', [
            'template_id' => $template->id,
            'scheduled_start_at' => '2026-01-01 10:00:00',
        ]);
    }

    public function test_rerunning_generation_is_idempotent(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $template = $this->makeTemplate(['generate_days_ahead' => 3]);
        $action = app(GenerateTournamentsFromTemplatesAction::class);

        $action->execute();
        $second = $action->execute();

        $this->assertSame(0, $second['generated']);
        $this->assertSame(4, Tournament::where('template_id', $template->id)->count());
    }

    public function test_a_conflicting_slot_from_another_source_is_skipped_and_logged(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $template = $this->makeTemplate(['generate_days_ahead' => 2]);

        // A manually-created tournament already occupies tomorrow's slot.
        Tournament::factory()->create([
            'game_id' => $template->game_id,
            'scheduled_start_at' => '2026-01-02 10:00:00',
            'status' => 'upcoming',
        ]);

        $result = app(GenerateTournamentsFromTemplatesAction::class)->execute();

        $this->assertSame(1, $result['skipped_conflicts']);
        $this->assertSame(2, $result['generated']); // today + day-after-tomorrow, tomorrow skipped
        $this->assertDatabaseMissing('tournaments', [
            'template_id' => $template->id,
            'scheduled_start_at' => '2026-01-02 10:00:00',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tournament.generation_conflict']);
    }

    public function test_an_inactive_template_is_skipped_when_generating_all(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $template = $this->makeTemplate(['is_active' => false]);

        app(GenerateTournamentsFromTemplatesAction::class)->execute();

        $this->assertSame(0, Tournament::where('template_id', $template->id)->count());
    }

    public function test_generating_for_a_single_template_ignores_the_is_active_flag(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $template = $this->makeTemplate(['is_active' => false, 'generate_days_ahead' => 1]);

        $result = app(GenerateTournamentsFromTemplatesAction::class)->execute($template);

        $this->assertSame(2, $result['generated']);
    }
}
