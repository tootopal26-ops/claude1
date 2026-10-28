<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Day / Night mode preference per portal, e.g. {"admin": "light"}. A UI
     * setting only; it carries nothing sensitive.
     */
    public function up(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->json('theme_modes')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->dropColumn('theme_modes');
        });
    }
};
