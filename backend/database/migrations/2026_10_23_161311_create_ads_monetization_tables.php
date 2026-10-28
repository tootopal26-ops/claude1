<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ads & Monetization: ad units and placements (Admin-configured), verified rewarded-ad
 * claims, the server-to-server callback log, client ad telemetry, tournament tickets,
 * daily login rewards, Ads Earning preferences, provider revenue reports and the weekly
 * revenue-share settlements, fixed-reward campaigns, rewarded redeem-code reservations and
 * the once-per-withdrawal post-success placement record.
 *
 * Every rule that protects money is also a database constraint: one reward per provider
 * event, one ticket per verified claim, one daily reward per player per day, one allocation
 * per player per settlement, one post-success attempt per withdrawal.
 */
return new class extends Migration
{
    /**
     * @var list<array{key: string, name: string, format: string, supported: bool, insert_every: ?int, description: string}>
     */
    private const array PLACEMENTS = [
        ['key' => 'home_hero', 'name' => 'Home — above the hero', 'format' => 'banner', 'supported' => true, 'insert_every' => null, 'description' => 'A labelled banner above the Home hero carousel.'],
        ['key' => 'home_game_card', 'name' => 'Home — game card tap', 'format' => 'interstitial', 'supported' => true, 'insert_every' => null, 'description' => 'An optional interstitial before a game\'s tournament list opens. If no ad is ready the game opens normally.'],
        ['key' => 'app_open', 'name' => 'App open', 'format' => 'app_open', 'supported' => false, 'insert_every' => null, 'description' => 'Not offered: the LevelPlay React Native plugin (unity-levelplay-mediation 9.3.0) has no app-open format, and an interstitial at launch is not an acceptable substitute.'],
        ['key' => 'tournament_ticket', 'name' => 'Tournament ticket', 'format' => 'rewarded', 'supported' => true, 'insert_every' => null, 'description' => 'Rewarded ads a player watches to earn the tickets a free tournament requires.'],
        ['key' => 'rewarded_general', 'name' => 'Reward campaigns', 'format' => 'rewarded', 'supported' => true, 'insert_every' => null, 'description' => 'Optional rewarded ads for fixed-reward campaigns.'],
        ['key' => 'ads_earning', 'name' => 'Ads Earning', 'format' => 'rewarded', 'supported' => true, 'insert_every' => null, 'description' => 'Rewarded ads that count toward the weekly Ads Earning revenue share.'],
        ['key' => 'redeem_verification', 'name' => 'Redeem code verification', 'format' => 'rewarded', 'supported' => true, 'insert_every' => null, 'description' => 'The rewarded ad a redeem code can require before its coins are credited.'],
        ['key' => 'daily_login', 'name' => 'Daily login reward', 'format' => 'rewarded', 'supported' => true, 'insert_every' => null, 'description' => 'The rewarded ad behind the daily login reward.'],
        ['key' => 'withdrawal_success', 'name' => 'After a paid withdrawal', 'format' => 'interstitial', 'supported' => true, 'insert_every' => null, 'description' => 'One optional interstitial after a withdrawal is marked paid by the server — never while it is pending, rejected or failed. A full-screen native format does not exist in the SDK; the interstitial is the closest supported full-screen format.'],
        ['key' => 'leaderboard_feed', 'name' => 'Leaderboard feed', 'format' => 'native', 'supported' => true, 'insert_every' => 8, 'description' => 'A labelled native ad between leaderboard rows, after every N players.'],
        ['key' => 'tournament_feed', 'name' => 'Tournament list feed', 'format' => 'native', 'supported' => true, 'insert_every' => 5, 'description' => 'A labelled native ad between tournament cards, after every N tournaments.'],
        ['key' => 'wallet_profile', 'name' => 'Wallet', 'format' => 'banner', 'supported' => true, 'insert_every' => null, 'description' => 'A small labelled banner at the end of the Wallet screen (never on Profile, where Log out stays the last item).'],
    ];

    public function up(): void
    {
        Schema::create('ad_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->enum('format', ['rewarded', 'interstitial', 'banner', 'native']);
            $table->enum('environment', ['development', 'staging', 'production'])->default('production');
            // The LevelPlay ad unit ID the app loads. Network instances (AdMob ad unit IDs,
            // Unity Ads placement IDs) are configured inside LevelPlay's own dashboard; the
            // two reference fields below only record them for the team, the app never uses them.
            $table->string('levelplay_ad_unit_id', 120);
            $table->string('admob_reference', 160)->nullable();
            $table->string('unity_reference', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_loaded_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['environment', 'levelplay_ad_unit_id']);
        });

        Schema::create('ad_placements', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('format', 20);
            $table->boolean('is_supported')->default(true);
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('ad_unit_id')->nullable()->constrained('ad_units')->nullOnDelete();
            $table->unsignedSmallInteger('daily_cap_per_user')->nullable();
            $table->unsignedInteger('cooldown_seconds')->nullable();
            $table->unsignedTinyInteger('insert_every')->nullable();
            $table->string('description', 400);
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('ad_placements')->insert(array_map(fn (array $placement) => [
            'key' => $placement['key'],
            'name' => $placement['name'],
            'format' => $placement['format'],
            'is_supported' => $placement['supported'],
            'is_enabled' => false,
            'insert_every' => $placement['insert_every'],
            'description' => $placement['description'],
            'created_at' => $now,
            'updated_at' => $now,
        ], self::PLACEMENTS));

        // Client-reported SDK telemetry: requests, loads, no-fills, impressions, errors and the
        // SDK's impression-level revenue estimate. Unverified by nature: it feeds reports and
        // fill/error statistics, never a reward or a settlement.
        Schema::create('ad_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('placement_key', 40);
            $table->enum('event', ['init_success', 'init_failed', 'requested', 'loaded', 'no_fill', 'load_failed', 'impression', 'display_failed', 'clicked', 'closed', 'rewarded_client']);
            $table->string('ad_network', 60)->nullable();
            $table->string('ad_unit_id', 120)->nullable();
            $table->decimal('revenue_usd', 14, 8)->nullable();
            $table->string('revenue_precision', 30)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['placement_key', 'event', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index('occurred_at');
        });

        Schema::create('ad_reward_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->decimal('reward_coins', 10, 2);
            $table->unsignedSmallInteger('daily_limit_per_user')->default(1);
            $table->decimal('budget_coins', 12, 2)->nullable();
            $table->decimal('credited_coins', 12, 2)->default(0);
            $table->boolean('is_active')->default(false);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tournaments', function (Blueprint $table) {
            // Rewarded-ad tickets a player must earn before joining (free tournaments only; 0 = none).
            $table->unsignedTinyInteger('required_ad_tickets')->default(0)->after('entry_fee');
        });

        Schema::table('redeem_campaigns', function (Blueprint $table) {
            $table->boolean('requires_rewarded_ad')->default(false)->after('is_active');
        });

        Schema::create('redeem_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redeem_campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['reserved', 'completed', 'expired'])->default('reserved');
            $table->dateTime('expires_at');
            $table->timestamps();

            // One reservation per player per campaign; an expired one is reused on the next try.
            $table->unique(['redeem_campaign_id', 'user_id']);
            $table->index(['redeem_campaign_id', 'status', 'expires_at']);
        });

        Schema::create('daily_reward_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('reward_day');
            // Drawn by the server, within the Admin range, when the day's claim is first started.
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['reserved', 'credited'])->default('reserved');
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            // At most one reward per player per configured calendar day.
            $table->unique(['user_id', 'reward_day']);
            $table->index(['reward_day', 'status']);
        });

        Schema::create('daily_reward_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('reward_day');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'reward_day']);
        });

        Schema::create('rewarded_claims', function (Blueprint $table) {
            $table->id();
            // The opaque token the app hands to LevelPlay as its dynamic user ID; LevelPlay echoes
            // it in the signed server-to-server callback, which is what ties a completion to this claim.
            $table->char('token', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('purpose', ['tournament_ticket', 'daily_reward', 'ads_earning', 'redeem_code', 'campaign']);
            $table->string('placement_key', 40);
            $table->foreignId('tournament_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('daily_reward_claim_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('redeem_reservation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ad_reward_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'verified', 'rejected', 'expired', 'cancelled'])->default('pending');
            $table->dateTime('expires_at');
            $table->string('provider', 20)->nullable();
            $table->string('provider_event_id', 120)->nullable();
            $table->enum('verification_method', ['levelplay_s2s', 'simulated'])->nullable();
            // Whether this completion counts toward Ads Earning — decided once, at verification,
            // from the player's preference and eligibility at that moment (never retroactively).
            $table->boolean('counts_for_earning')->default(false);
            $table->decimal('reward_coins', 10, 2)->nullable();
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->string('failure_reason', 120)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // One reward per provider event, ever.
            $table->unique(['provider', 'provider_event_id']);
            $table->index(['user_id', 'purpose', 'status', 'created_at']);
            $table->index(['purpose', 'status', 'verified_at']);
        });

        Schema::create('tournament_ad_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Each verified claim buys exactly one ticket for exactly the tournament it was started for.
            $table->foreignId('rewarded_claim_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tournament_id', 'user_id']);
        });

        Schema::create('ad_callback_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 120)->nullable();
            $table->string('outcome', 40);
            $table->boolean('signature_valid')->default(false);
            $table->foreignId('rewarded_claim_id')->nullable()->constrained()->nullOnDelete();
            // The callback's parameters with the signature removed — never a secret.
            $table->json('payload');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['outcome', 'created_at']);
            $table->index('event_id');
        });

        Schema::create('ads_earning_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        // Provider-reported revenue, entered or imported by an Admin from the LevelPlay
        // dashboard. Only `finalized` rows can be settled.
        Schema::create('ad_revenue_reports', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('provider', 30)->default('levelplay');
            $table->string('placement_key', 40)->nullable();
            $table->decimal('revenue_usd', 14, 6);
            // estimated: provider figure not final yet; finalized: settles; disputed: held back, never settled.
            $table->enum('status', ['estimated', 'finalized', 'disputed'])->default('estimated');
            $table->string('source', 120);
            $table->string('notes', 500)->nullable();
            $table->foreignId('entered_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['period_start', 'period_end']);
        });

        Schema::create('ad_settlement_batches', function (Blueprint $table) {
            $table->id();
            // Exact UTC bounds: from one weekly cutoff (Admin day + time in the settlement
            // timezone) to the next. Units verified in [start, end) belong to this batch.
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->enum('status', ['pending', 'calculated', 'crediting', 'completed', 'failed'])->default('pending');
            $table->decimal('eligible_revenue_usd', 14, 6)->default(0);
            $table->decimal('business_share_pct', 5, 2);
            $table->decimal('user_share_pct', 5, 2);
            $table->decimal('business_share_usd', 14, 6)->default(0);
            $table->decimal('user_pool_usd', 14, 6)->default(0);
            $table->decimal('usd_inr_rate', 12, 4)->nullable();
            $table->string('rate_source', 160)->nullable();
            $table->timestamp('rate_at')->nullable();
            $table->decimal('user_pool_inr', 14, 4)->default(0);
            $table->decimal('coins_per_inr', 10, 2);
            $table->string('policy_version', 40);
            $table->unsignedInteger('total_units')->default(0);
            $table->unsignedInteger('eligible_users')->default(0);
            $table->decimal('total_coins_allocated', 14, 2)->default(0);
            $table->decimal('total_coins_credited', 14, 2)->default(0);
            $table->decimal('remainder_coins', 14, 4)->default(0);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // One settlement per period.
            $table->unique(['period_start', 'period_end']);
        });

        Schema::create('ad_settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_settlement_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('units');
            $table->decimal('coins', 12, 2);
            $table->enum('status', ['pending', 'credited', 'failed', 'below_minimum'])->default('pending');
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            // One allocation per player per settlement.
            $table->unique(['ad_settlement_batch_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('withdrawal_ad_attempts', function (Blueprint $table) {
            $table->id();
            // The optional post-success placement is attempted at most once per withdrawal.
            $table->foreignId('withdrawal_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        foreach ([
            ['key' => 'ads.view', 'label' => 'View ad analytics, settlements and logs (read-only)', 'group' => 'Ads & Monetization'],
            ['key' => 'ads.manage', 'label' => 'Configure ads, rewards, revenue share and settle Ads Earning', 'group' => 'Ads & Monetization'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                ['label' => $permission['label'], 'group' => $permission['group'], 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        foreach (['ads.view', 'ads.manage'] as $key) {
            $id = DB::table('permissions')->where('key', $key)->value('id');
            if ($id !== null) {
                DB::table('permission_preset_permission')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }

        Schema::dropIfExists('withdrawal_ad_attempts');
        Schema::dropIfExists('ad_settlement_allocations');
        Schema::dropIfExists('ad_settlement_batches');
        Schema::dropIfExists('ad_revenue_reports');
        Schema::dropIfExists('ads_earning_preferences');
        Schema::dropIfExists('ad_callback_logs');
        Schema::dropIfExists('tournament_ad_tickets');
        Schema::dropIfExists('rewarded_claims');
        Schema::dropIfExists('daily_reward_dismissals');
        Schema::dropIfExists('daily_reward_claims');
        Schema::dropIfExists('redeem_reservations');

        Schema::table('redeem_campaigns', function (Blueprint $table) {
            $table->dropColumn('requires_rewarded_ad');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('required_ad_tickets');
        });

        Schema::dropIfExists('ad_reward_campaigns');
        Schema::dropIfExists('ad_events');
        Schema::dropIfExists('ad_placements');
        Schema::dropIfExists('ad_units');
    }
};
