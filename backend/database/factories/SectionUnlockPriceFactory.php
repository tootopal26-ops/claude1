<?php

namespace Database\Factories;

use App\Models\SectionUnlockPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SectionUnlockPrice>
 */
class SectionUnlockPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'section_key' => 'leaderboard',
            'price_coins' => 250,
            'is_enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }
}
