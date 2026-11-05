<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Android build releases Admin can upload and mark "current" for players to download — the
 * APK/AAB upload + version management the project is distributed privately without a store
 * (see docs/mobile-release.md). The uploaded file itself is a normal StorageAsset (bucket
 * `app_releases`); this table only carries the release's own metadata and which one is live.
 * app_release_downloads is a plain per-download log (no foreign user — the public download
 * endpoint is unauthenticated) used only for the admin analytics view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 10)->default('android');
            $table->string('version_name', 40);
            $table->unsignedInteger('version_code');
            $table->text('release_notes')->nullable();
            $table->foreignId('file_asset_id')->constrained('storage_assets')->restrictOnDelete();
            $table->boolean('is_current')->default(false);
            $table->unsignedBigInteger('download_count')->default(0);
            $table->foreignId('uploaded_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'version_code']);
            $table->index(['platform', 'is_current']);
        });

        Schema::create('app_release_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_release_id')->constrained('app_releases')->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('downloaded_at');

            $table->index(['app_release_id', 'downloaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_release_downloads');
        Schema::dropIfExists('app_releases');
    }
};
