<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redeem codes. A campaign owns exactly one five-character code (unique in the
 * database) that up to total_capacity different players can redeem, once each.
 * The reward is stored on the campaign and copied onto each redemption — the app
 * never supplies an amount. Redemptions are unique per (campaign, player) and the
 * attempts table keeps a history of every try, successful or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redeem_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->char('code', 5)->unique();
            $table->unsignedInteger('total_capacity');
            $table->unsignedInteger('reward_coins');
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->dateTime('expires_at');
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });

        Schema::create('redeem_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redeem_campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('coins');
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['redeem_campaign_id', 'user_id']);
        });

        Schema::create('redeem_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('redeem_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('submitted_code', 16);
            $table->string('result', 24);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['redeem_campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redeem_attempts');
        Schema::dropIfExists('redeem_redemptions');
        Schema::dropIfExists('redeem_campaigns');
    }
};
