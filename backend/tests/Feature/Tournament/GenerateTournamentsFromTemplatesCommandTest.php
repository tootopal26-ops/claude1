<?php

namespace Tests\Feature\Tournament;

use App\Models\Game;
use App\Models\RecurringTournamentTemplate;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GenerateTournamentsFromTemplatesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_command_generates_occurrences_for_active_templates(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $template = RecurringTournamentTemplate::create([
            'game_id' => Game::factory()->create()->id,
            'title' => 'Scheduled Cup',
            'mode' => 'solo',
            'entry_fee' => 0,
            'total_slots' => 48,
            'frequency' => 'daily',
            'time_of_day' => '10:00',
            'generate_days_ahead' => 2,
            'is_active' => true,
        ]);

        $this->artisan('tournaments:generate-from-templates')->assertSuccessful();

        $this->assertSame(3, Tournament::where('template_id', $template->id)->count());
    }
}
