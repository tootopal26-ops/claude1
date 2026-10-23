<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The prizes a period pays are frozen onto it at the moment it finalizes
 * ({"rank": coins}). Editing prize rules later never changes what an already
 * finalized period pays, and re-running a finalization can't pay ranks that
 * were not prized when the period closed. Periods finalized before this column
 * existed keep null and are settled from the original config values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaderboard_periods', function (Blueprint $table) {
            $table->json('prize_snapshot')->nullable()->after('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('leaderboard_periods', function (Blueprint $table) {
            $table->dropColumn('prize_snapshot');
        });
    }
};
