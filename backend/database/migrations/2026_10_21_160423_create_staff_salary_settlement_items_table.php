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
        Schema::create('staff_salary_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_salary_settlement_id')->constrained('staff_salary_settlements')->cascadeOnDelete();
            $table->foreignId('staff_salary_event_id')->constrained('staff_salary_events')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            // An event settles at most once, ever — not just once per
            // settlement, which a (settlement_id, event_id) pair alone
            // would allow.
            $table->unique('staff_salary_event_id');
            $table->index('staff_salary_settlement_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_salary_settlement_items');
    }
};
