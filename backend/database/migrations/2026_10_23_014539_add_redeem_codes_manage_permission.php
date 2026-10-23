<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Puts the new permission key in place with `php artisan migrate` (see add_games_manage_permission). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['key' => 'redeem_codes.manage'],
            ['label' => 'Create and manage redeem-code campaigns', 'group' => 'Wallet & Payments', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'redeem_codes.manage')->value('id');

        if ($id !== null) {
            DB::table('permission_preset_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
