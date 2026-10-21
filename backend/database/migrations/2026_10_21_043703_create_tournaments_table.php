<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            $table->string('image_path')->nullable();
            $table->enum('mode', ['solo', 'duo', 'squad']);
            $table->decimal('entry_fee', 10, 2)->default(0);
            $table->decimal('per_kill_reward', 10, 2)->default(0);
            $table->decimal('prize_pool', 10, 2)->default(0);
            $table->unsignedInteger('total_slots');
            $table->unsignedInteger('min_players')->nullable();
            $table->dateTime('scheduled_start_at');
            $table->dateTime('credentials_released_at')->nullable();
            $table->enum('status', ['upcoming', 'ongoing', 'completed', 'cancelled'])->default('upcoming');
            $table->string('cancellation_reason')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
