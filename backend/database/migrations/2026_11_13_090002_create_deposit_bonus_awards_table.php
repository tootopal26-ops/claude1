<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_bonus_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Unique: idempotency per deposit order — a duplicate webhook delivery for the
            // same order can never award the bonus twice. "First deposit only" eligibility
            // (at most one row per user) is enforced in DepositBonusService inside the same
            // locked transaction as the credit, not by a database constraint, because the
            // admin can switch that rule off and allow further awards up to a lifetime cap.
            $table->foreignId('deposit_order_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_bonus_awards');
    }
};
