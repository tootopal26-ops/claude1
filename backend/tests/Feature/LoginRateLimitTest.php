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
}
