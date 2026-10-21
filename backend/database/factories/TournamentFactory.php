<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tournament>
 */
class TournamentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'title' => fake()->words(3, true).' Tournament',
            'description' => fake()->sentence(),
            'rules' => fake()->paragraph(),
            'mode' => 'solo',
            'entry_fee' => 0,
            'per_kill_reward' => 0,
            'prize_pool' => 0,
            'total_slots' => 48,
            'min_players' => null,
            'scheduled_start_at' => now()->addDay(),
            'status' => 'upcoming',
        ];
    }

    public function paid(float $fee = 50): static
    {
        return $this->state(fn () => ['entry_fee' => $fee]);
    }

    public function full(): static
    {
        return $this->state(fn () => ['total_slots' => 1]);
    }
}
