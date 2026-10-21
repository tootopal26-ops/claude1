<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\StaffUser;
use App\Models\Tournament;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed local/demo data only — no production credentials or real user data.
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        StaffUser::firstOrCreate(
            ['username' => 'admin'],
            [
                'display_name' => 'Mini Max Admin',
                'password' => Hash::make(env('SEED_ADMIN_PASSWORD', 'change-me-now')),
                'is_super_admin' => true,
                'is_active' => true,
            ]
        );

        $game = Game::firstOrCreate(
            ['name' => 'Free Fire'],
            ['display_order' => 1, 'is_active' => true]
        );

        Tournament::firstOrCreate(
            ['title' => 'Weekly Solo Showdown'],
            [
                'game_id' => $game->id,
                'description' => 'Free weekly Solo tournament for every Mini Max player.',
                'rules' => 'Standard Free Fire Solo ranked rules apply. No teaming, no emulators.',
                'mode' => 'solo',
                'entry_fee' => 0,
                'per_kill_reward' => 0,
                'prize_pool' => 0,
                'total_slots' => 48,
                'scheduled_start_at' => now()->addDay(),
                'status' => 'upcoming',
            ]
        );
    }
}
