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
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('upi_id');
            $table->enum('status', ['pending', 'approved', 'processing', 'paid', 'rejected', 'failed'])->default('pending');

            $table->foreignId('reviewed_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('rejection_reason')->nullable();
            $table->string('failure_reason')->nullable();

            $table->string('settlement_reference')->nullable();
            $table->foreignId('settled_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
