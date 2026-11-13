<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            // Unique: one account can be the "referred" side of at most one referral,
            // ever — enforced here at the database level, not only in application code.
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('referral_code_used', 12);
            // The admin-configured amounts in effect at the moment this referral was
            // created — recorded here (not re-read from current settings later) so a
            // later admin change never rewrites history.
            $table->decimal('new_player_reward', 10, 2);
            $table->decimal('referrer_reward', 10, 2);
            // In this design a row only ever exists once both rewards have been credited
            // inside the same registration transaction, so 'completed' is the only value
            // this reaches in practice; 'failed' exists only for the (should-be-impossible)
            // case a reward credit throws after the relationship is validated.
            $table->enum('status', ['completed', 'failed'])->default('completed');
            $table->foreignId('new_player_wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->foreignId('referrer_wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['referrer_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
