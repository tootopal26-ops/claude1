<?php

namespace Database\Factories;

use App\Models\SectionUnlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SectionUnlock>
 */
class SectionUnlockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'section_key' => 'leaderboard',
            'price_paid' => 250,
        ];
    }
}
