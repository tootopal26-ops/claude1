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
        Schema::create('tournament_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('room_id');
            $table->text('room_password');
            $table->foreignId('released_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_credentials');
    }
};
