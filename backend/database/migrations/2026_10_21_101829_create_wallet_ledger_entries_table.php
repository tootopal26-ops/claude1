<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->enum('balance', ['deposit', 'winnings']);
            // Signed: positive = credit, negative = debit. Never a float —
            // always a decimal column, always cast decimal:2 on the model.
            $table->decimal('amount', 12, 2);
            // Snapshot of that balance (not the wallet total) immediately
            // after this entry, so a statement can be rendered without
            // replaying the whole ledger.
            $table->decimal('balance_after', 12, 2);
            $table->string('reason')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->string('idempotency_key');
            $table->timestamps();

            // One logical operation (one idempotency key) may need to
            // affect both balances at once (a split entry-fee debit), so
            // the key alone isn't unique — but it may touch each balance
            // at most once, which is what actually prevents double-
            // processing either half of a split operation.
            $table->unique(['idempotency_key', 'balance'], 'ledger_idempotency_unique');
            $table->index(['user_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
