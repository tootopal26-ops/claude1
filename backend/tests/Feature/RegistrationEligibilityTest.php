<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrationEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $dateOfBirth, string $unique): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'username' => "player_{$unique}",
            'mobile' => '90000'.substr($unique, 0, 5),
            'email' => "{$unique}@example.com",
            'date_of_birth' => $dateOfBirth,
            'state' => 'Maharashtra',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ]);
    }

    public function test_a_dob_on_the_same_day_and_month_as_today_is_rejected(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 21), function () {
            $this->register('1995-10-21', 'win0')->assertJsonValidationErrors('date_of_birth');
        });
    }

    public function test_a_dob_at_the_far_edge_of_the_window_is_rejected(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 21), function () {
            // 2026-10-21 + 6 days = 2026-10-27, the last excluded day/month.
            $this->register('1995-10-27', 'win6')->assertJsonValidationErrors('date_of_birth');
        });
    }

    public function test_a_dob_one_day_past_the_window_is_allowed(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 21), function () {
            $this->register('1995-10-28', 'win7')->assertCreated();
        });
    }

    public function test_a_dob_one_day_before_the_window_is_allowed(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 21), function () {
            $this->register('1995-10-20', 'winm1')->assertCreated();
        });
    }

    public function test_the_exclusion_window_correctly_wraps_across_a_year_boundary(): void
    {
        $this->travelTo(Carbon::create(2026, 12, 29), function () {
            // 2026-12-29 + 4 days = Jan 2 (next year) — still inside the window.
            $this->register('1998-01-02', 'wrap4')->assertJsonValidationErrors('date_of_birth');

            // 2026-12-29 + 8 days = Jan 6 — outside the window.
            $this->register('1998-01-06', 'wrap8')->assertCreated();
        });
    }

    public function test_a_player_comfortably_over_the_minimum_age_with_a_window_safe_dob_is_allowed(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 15), function () {
            $this->register('2000-01-01', 'overage')->assertCreated();
        });
    }

    public function test_registration_rejects_a_future_date_of_birth(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 15), function () {
            $this->register('2027-01-01', 'futuredob')->assertJsonValidationErrors('date_of_birth');
        });
    }
}
