<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coin-priced section unlocks. A price row exists only for a section the Admin has
 * priced (the registry of unlockable sections lives in config/minimax.php); a section
 * with no row, or with unlocking switched off, is free. An unlock is the entitlement a
 * player buys: unique per (player, section), so a second purchase cannot exist even if
 * the application check were bypassed, and it points at the ledger row that paid for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_unlock_prices', function (Blueprint $table) {
            $table->id();
            $table->string('section_key', 40)->unique();
            $table->unsignedInteger('price_coins');
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('section_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('section_key', 40);
            $table->unsignedInteger('price_paid');
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'section_key']);
            $table->index('section_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_unlocks');
        Schema::dropIfExists('section_unlock_prices');
    }
};
