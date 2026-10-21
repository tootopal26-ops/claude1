<?php

namespace Database\Factories;

use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TournamentEntry>
 */
class TournamentEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tournament_id' => Tournament::factory(),
            'user_id' => User::factory(),
            'entry_fee_paid' => 0,
            'status' => 'confirmed',
        ];
    }
}
