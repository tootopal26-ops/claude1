<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_entry_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->string('in_game_name', 20);
            $table->string('uid', 12)->nullable();
            $table->timestamps();

            $table->unique(['tournament_entry_id', 'slot_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_entry_players');
    }
};
