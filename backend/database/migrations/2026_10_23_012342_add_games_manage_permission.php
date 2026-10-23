<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission catalog is normally seeded, but a deployed database only picks
 * up new keys when a seeder is run by hand. This puts the new key in place with
 * `php artisan migrate`, so the page that requires it is never reachable without
 * a way to grant it. (Super-admins hold every permission implicitly.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['key' => 'games.manage'],
            ['label' => 'Manage tournament game cards (home-screen images and order)', 'group' => 'Tournaments', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'games.manage')->value('id');

        if ($id !== null) {
            DB::table('permission_preset_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
