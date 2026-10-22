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
        Schema::create('staff_salary_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_user_id')->constrained('staff_users')->cascadeOnDelete();
            $table->foreignId('staff_salary_rule_id')->nullable()->constrained('staff_salary_rules')->nullOnDelete();
            $table->string('action_type');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 10, 2);
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->unique(['action_type', 'source_type', 'source_id']);
            $table->index(['staff_user_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_salary_events');
    }
};
