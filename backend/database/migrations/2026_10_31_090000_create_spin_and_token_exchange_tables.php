<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Spin & Token Rewards system: a rewarded-ad spin wheel pays out 1-5 tokens (a weighted
 * draw the server makes, never the client), and tokens are exchanged in fixed tiers for coins
 * credited to the existing Deposit wallet — also only after a rewarded ad is verified.
 *
 * Tokens get their own small ledger (user_token_balances + token_ledger_entries), the same
 * shape as the coin wallet's own ledger: the ledger is the source of truth, the cached balance
 * column exists only so reads don't sum it every time, and every credit/debit is idempotent on
 * a caller-supplied key. Both new rewarded-ad purposes ('spin', 'token_exchange') plug into the
 * existing RewardedClaimService exactly like daily_reward/ads_earning/redeem_code do — nothing
 * here invents a second ad-verification path.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('ad_placements')->insert([
            [
                'key' => 'spin',
                'name' => 'Spin wheel',
                'format' => 'rewarded',
                'is_supported' => true,
                'is_enabled' => false,
                'insert_every' => null,
                'description' => 'The rewarded ad a player watches for one spin of the token wheel.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'token_exchange',
                'name' => 'Token exchange',
                'format' => 'rewarded',
                'is_supported' => true,
                'is_enabled' => false,
                'insert_every' => null,
                'description' => 'The rewarded ad required before tokens are exchanged for coins.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'spin_feed',
                'name' => 'Spin screen',
                'format' => 'native',
                'is_supported' => true,
                'is_enabled' => false,
                'insert_every' => null,
                'description' => 'One labelled native ad on the Spin screen, above the bottom navigation bar.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::create('user_token_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Tokens are always whole numbers (every spin and every exchange tier is an
            // integer), so a plain unsigned int is exact — no decimal/bcmath needed here.
            $table->unsignedInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('token_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['spin_reward', 'exchange_debit', 'admin_adjustment']);
            // Signed: positive for a spin reward, negative for an exchange debit.
            $table->integer('amount');
            $table->integer('balance_after');
            $table->string('reason', 191)->nullable();
            $table->nullableMorphs('source');
            // Every credit/debit is idempotent: a retried request with the same key returns
            // the original entry instead of moving the balance again.
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('spin_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // One spin attempt per verified claim — the claim is the idempotency boundary.
            $table->foreignId('rewarded_claim_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('winning_number');
            $table->unsignedTinyInteger('tokens_awarded');
            // What the configured probabilities were at the moment of the draw, for audit —
            // an admin who changes the odds later never obscures what a past spin actually used.
            $table->json('probabilities_snapshot');
            $table->foreignId('token_ledger_entry_id')->nullable()->constrained('token_ledger_entries')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['winning_number', 'created_at']);
        });

        Schema::create('token_exchange_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // One exchange per verified claim — same idempotency boundary as spins.
            $table->foreignId('rewarded_claim_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('tier_index');
            $table->unsignedInteger('tokens_required');
            $table->decimal('coins_received', 10, 2);
            $table->decimal('bonus_percent', 5, 2)->default(0);
            $table->enum('status', ['credited', 'failed'])->default('failed');
            $table->string('failure_reason', 191)->nullable();
            $table->foreignId('token_ledger_entry_id')->nullable()->constrained('token_ledger_entries')->nullOnDelete();
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('rewarded_claims', function (Blueprint $table) {
            // 'spin' claims reward tokens, not coins — a separate column from reward_coins
            // (which campaign/daily_reward/redeem_code already use) rather than overloading it.
            $table->unsignedTinyInteger('reward_tokens')->nullable()->after('reward_coins');
            // Which of the 10 fixed exchange tiers a 'token_exchange' claim is for.
            $table->unsignedTinyInteger('exchange_tier_index')->nullable()->after('ad_reward_campaign_id');
        });

        // The purpose enum was fixed to the original 5 values; spin and token_exchange add two
        // more. Schema::change() rewrites the column portably (a full table rebuild on SQLite,
        // the native MODIFY on MySQL/MariaDB) so the same migration works against both — the
        // app's MySQL database and the test suite's SQLite connection.
        Schema::table('rewarded_claims', function (Blueprint $table) {
            $table->enum('purpose', ['tournament_ticket', 'daily_reward', 'ads_earning', 'redeem_code', 'campaign', 'spin', 'token_exchange'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('rewarded_claims', function (Blueprint $table) {
            $table->enum('purpose', ['tournament_ticket', 'daily_reward', 'ads_earning', 'redeem_code', 'campaign'])->change();
        });

        Schema::table('rewarded_claims', function (Blueprint $table) {
            $table->dropColumn(['reward_tokens', 'exchange_tier_index']);
        });
        Schema::dropIfExists('token_exchange_transactions');
        Schema::dropIfExists('spin_attempts');
        Schema::dropIfExists('token_ledger_entries');
        Schema::dropIfExists('user_token_balances');
        DB::table('ad_placements')->whereIn('key', ['spin', 'token_exchange', 'spin_feed'])->delete();
    }
};
