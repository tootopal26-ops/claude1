<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80)->nullable();
            $table->foreignId('image_asset_id')->nullable()->constrained('storage_assets')->nullOnDelete();
            $table->foreignId('thumbnail_asset_id')->nullable()->constrained('storage_assets')->nullOnDelete();
            $table->unsignedTinyInteger('focal_x')->default(50);
            $table->unsignedTinyInteger('focal_y')->default(50);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_banners');
    }
};
