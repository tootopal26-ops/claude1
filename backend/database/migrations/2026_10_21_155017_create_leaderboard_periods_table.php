<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leaderboard_periods', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['daily', 'weekly', 'monthly']);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->enum('status', ['provisional', 'finalized'])->default('provisional');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leaderboard_periods');
    }
};
