<?php

namespace Database\Factories;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevicePushToken>
 */
class DevicePushTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => fake()->unique()->uuid(),
            'platform' => 'android',
            'device_name' => fake()->word(),
            'last_used_at' => now(),
        ];
    }
}
