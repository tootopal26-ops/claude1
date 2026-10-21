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
        Schema::table('tournaments', function (Blueprint $table) {
            $table->foreign('template_id')->references('id')->on('recurring_tournament_templates')->nullOnDelete();

            // NULL template_id values don't collide with each other under a
            // unique index (MySQL treats each NULL as distinct), so this only
            // ever blocks the same template generating twice for the same
            // scheduled_start_at — exactly what makes generation idempotent.
            $table->unique(['template_id', 'scheduled_start_at'], 'tournaments_template_start_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropUnique('tournaments_template_start_unique');
            $table->dropForeign(['template_id']);
        });
    }
};
