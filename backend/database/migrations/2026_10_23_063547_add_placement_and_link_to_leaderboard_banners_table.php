<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One banner system for two places in the app: the leaderboard header (every existing
 * banner, hence the default) and the Home hero carousel. A banner may carry a link the
 * app opens when it is tapped — an in-app route or an https address, validated on save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaderboard_banners', function (Blueprint $table) {
            $table->string('placement', 20)->default('leaderboard')->after('id');
            $table->string('link_url', 255)->nullable()->after('title');
            $table->index(['placement', 'is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::table('leaderboard_banners', function (Blueprint $table) {
            $table->dropIndex(['placement', 'is_active', 'display_order']);
            $table->dropColumn(['placement', 'link_url']);
        });
    }
};
