<?php

namespace Tests\Feature\Leaderboard;

use App\Domain\Leaderboard\LeaderboardPeriodCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaderboardPeriodCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_bounds_are_midnight_to_midnight_ist(): void
    {
        // 10:00 UTC on Jan 15 is 15:30 IST on Jan 15 — no date crossover here.
        Carbon::setTestNow('2026-01-15 10:00:00');

        $bounds = LeaderboardPeriodCalculator::currentBounds('daily');

        // IST midnight Jan 15 00:00 = UTC Jan 14 18:30.
        $this->assertSame('2026-01-14 18:30:00', $bounds['start']->toDateTimeString());
        $this->assertSame('2026-01-15 18:30:00', $bounds['end']->toDateTimeString());
    }

    public function test_daily_bounds_cross_the_ist_date_line_even_when_utc_date_has_not_turned_over(): void
    {
        // 20:00 UTC on Jan 15 is already 01:30 IST on Jan 16 — the IST
        // calendar day has turned over even though the UTC date has not.
        Carbon::setTestNow('2026-01-15 20:00:00');

        $bounds = LeaderboardPeriodCalculator::currentBounds('daily');

        $this->assertSame('2026-01-15 18:30:00', $bounds['start']->toDateTimeString());
        $this->assertSame('2026-01-16 18:30:00', $bounds['end']->toDateTimeString());
    }

    public function test_weekly_bounds_start_on_monday_ist_regardless_of_the_current_day(): void
    {
        // 2026-01-15 is a Thursday.
        Carbon::setTestNow('2026-01-15 10:00:00');

        $bounds = LeaderboardPeriodCalculator::currentBounds('weekly');

        $this->assertSame('monday', strtolower($bounds['start']->timezone('Asia/Kolkata')->format('l')));
        $this->assertEquals(7, $bounds['start']->diffInDays($bounds['end']));
    }

    public function test_monthly_bounds_span_the_full_ist_calendar_month(): void
    {
        Carbon::setTestNow('2026-02-15 10:00:00');

        $bounds = LeaderboardPeriodCalculator::currentBounds('monthly');

        $this->assertSame(1, $bounds['start']->timezone('Asia/Kolkata')->day);
        $this->assertSame(2, $bounds['start']->timezone('Asia/Kolkata')->month);
        $this->assertSame(1, $bounds['end']->timezone('Asia/Kolkata')->day);
        $this->assertSame(3, $bounds['end']->timezone('Asia/Kolkata')->month);
    }
}
