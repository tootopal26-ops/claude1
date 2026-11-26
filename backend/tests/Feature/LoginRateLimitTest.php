<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The testing cache store persists across test methods in the same
        // process, but each test here needs a clean rate-limit bucket.
        Cache::flush();
    }

    public function test_the_sixth_login_attempt_within_a_minute_for_the_same_mobile_is_throttled(): void
    {
        $user = User::factory()->create(['mobile' => '9111111111']);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'mobile' => '9111111111',
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(422);
        }

        $sixth = $this->postJson('/api/v1/auth/login', [
            'mobile' => '9111111111',
            'password' => 'wrong-password',
        ]);

        $sixth->assertStatus(429);
    }

    public function test_throttling_one_mobile_number_does_not_affect_a_different_number(): void
    {
        User::factory()->create(['mobile' => '9111111111', 'password' => bcrypt('password123')]);
        $other = User::factory()->create(['mobile' => '9222222222', 'password' => bcrypt('password123')]);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'mobile' => '9111111111',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'mobile' => '9222222222',
            'password' => 'password123',
        ]);

        $response->assertOk();
    }

    public function test_the_sixth_login_attempt_within_a_minute_for_the_same_identifier_is_throttled(): void
    {
        User::factory()->create(['email' => 'throttle@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'identifier' => 'throttle@example.com',
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(422);
        }

        $sixth = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'throttle@example.com',
            'password' => 'wrong-password',
        ]);

        $sixth->assertStatus(429);
    }

    public function test_the_same_mobile_number_typed_differently_shares_one_rate_limit_bucket(): void
    {
        User::factory()->create(['mobile' => '9333333333']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['mobile' => '9333333333', 'password' => 'wrong'])->assertStatus(422);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/login', ['identifier' => '+91 9333333333', 'password' => 'wrong'])->assertStatus(422);
        }

        // That's 5 failures total across both field names/formats for the same
        // number — the 6th, in either form, must already be throttled.
        $sixth = $this->postJson('/api/v1/auth/login', ['identifier' => '09333333333', 'password' => 'wrong']);

        $sixth->assertStatus(429);
    }
}
