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
        Schema::create('recurring_tournament_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            $table->enum('mode', ['solo', 'duo', 'squad']);
            $table->decimal('entry_fee', 10, 2)->default(0);
            $table->decimal('per_kill_reward', 10, 2)->default(0);
            $table->decimal('prize_pool', 10, 2)->default(0);
            $table->unsignedInteger('total_slots');
            $table->unsignedInteger('min_players')->nullable();
            $table->enum('frequency', ['daily', 'weekly']);
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('time_of_day');
            $table->unsignedInteger('generate_days_ahead')->default(7);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_tournament_templates');
    }
};
