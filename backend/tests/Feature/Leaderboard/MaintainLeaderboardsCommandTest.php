<?php

namespace Tests\Feature\Leaderboard;

use App\Models\LeaderboardPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintainLeaderboardsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_creates_the_three_current_periods_when_none_exist(): void
    {
        $this->artisan('leaderboards:maintain')->assertSuccessful();

        $this->assertSame(3, LeaderboardPeriod::count());
        $this->assertSame(3, LeaderboardPeriod::where('status', 'provisional')->count());
    }

    public function test_it_finalizes_a_period_whose_end_has_passed(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        LeaderboardPeriod::create([
            'type' => 'daily',
            'period_start' => '2026-01-14 18:30:00',
            'period_end' => '2026-01-15 09:00:00', // already ended relative to "now"
            'status' => 'provisional',
        ]);

        $this->artisan('leaderboards:maintain')->assertSuccessful();

        $this->assertDatabaseHas('leaderboard_periods', [
            'period_start' => '2026-01-14 18:30:00',
            'status' => 'finalized',
        ]);
    }

    public function test_it_leaves_an_ongoing_period_provisional(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        $this->artisan('leaderboards:maintain')->assertSuccessful();

        $this->assertSame(3, LeaderboardPeriod::where('status', 'provisional')->count());
    }
}
