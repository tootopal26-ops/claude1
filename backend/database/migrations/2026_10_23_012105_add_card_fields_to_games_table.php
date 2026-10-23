<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournament Games become manageable home-screen cards: a short description,
 * a processed 16:9 image (kept as two storage assets: the card image and a
 * smaller thumbnail) and the crop position the admin picked. The legacy
 * image_path column is left untouched and unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('description', 200)->nullable()->after('name');
            $table->foreignId('image_asset_id')->nullable()->after('image_path')->constrained('storage_assets')->nullOnDelete();
            $table->foreignId('thumbnail_asset_id')->nullable()->after('image_asset_id')->constrained('storage_assets')->nullOnDelete();
            $table->unsignedTinyInteger('focal_x')->default(50)->after('thumbnail_asset_id');
            $table->unsignedTinyInteger('focal_y')->default(50)->after('focal_x');
            $table->index(['is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'display_order']);
            $table->dropConstrainedForeignId('thumbnail_asset_id');
            $table->dropConstrainedForeignId('image_asset_id');
            $table->dropColumn(['description', 'focal_x', 'focal_y']);
        });
    }
};
