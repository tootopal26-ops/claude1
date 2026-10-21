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
        Schema::create('tournament_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->unsignedInteger('rank');
            $table->unsignedInteger('kills')->default(0);
            $table->decimal('prize_awarded', 10, 2)->default(0);
            $table->foreignId('submitted_by_staff_id')->constrained('staff_users')->restrictOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('tournament_results')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tournament_entry_id', 'revision_number']);
            $table->index(['tournament_id', 'revision_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_results');
    }
};
