<?php

namespace Tests\Unit;

use App\Rules\MeetsMinimumAge;
use Carbon\Carbon;
use Tests\TestCase;

class MeetsMinimumAgeTest extends TestCase
{
    public function test_someone_who_turns_the_minimum_age_exactly_today_passes(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 15), function () {
            $failed = false;
            (new MeetsMinimumAge)->validate('date_of_birth', '2008-06-15', function () use (&$failed) {
                $failed = true;
            });

            $this->assertFalse($failed);
        });
    }

    public function test_someone_one_day_short_of_the_minimum_age_fails(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 15), function () {
            $failed = false;
            (new MeetsMinimumAge)->validate('date_of_birth', '2008-06-16', function () use (&$failed) {
                $failed = true;
            });

            $this->assertTrue($failed);
        });
    }

    public function test_someone_well_over_the_minimum_age_passes(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 15), function () {
            $failed = false;
            (new MeetsMinimumAge)->validate('date_of_birth', '1990-01-01', function () use (&$failed) {
                $failed = true;
            });

            $this->assertFalse($failed);
        });
    }
}
