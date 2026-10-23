<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable leaderboard prizes: one rule per period type (daily, weekly,
 * monthly — never shared) and one row per prized rank (1-100). A rank with no
 * row pays nothing. The prizes the platform already paid from config are copied
 * in as each rule's starting values, so nothing changes until an admin edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_prize_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('period_type', ['daily', 'weekly', 'monthly'])->unique();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('updated_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('leaderboard_prize_ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leaderboard_prize_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->unsignedInteger('coins');
            $table->timestamps();

            $table->unique(['leaderboard_prize_rule_id', 'rank']);
        });

        $now = now();

        foreach (['daily', 'weekly', 'monthly'] as $type) {
            $ruleId = DB::table('leaderboard_prize_rules')->insertGetId([
                'period_type' => $type,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ((array) config("minimax.leaderboard_rewards.{$type}", []) as $rank => $amount) {
                $coins = (int) round((float) $amount);

                if ($coins > 0 && (int) $rank >= 1 && (int) $rank <= 100) {
                    DB::table('leaderboard_prize_ranks')->insert([
                        'leaderboard_prize_rule_id' => $ruleId,
                        'rank' => (int) $rank,
                        'coins' => $coins,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_prize_ranks');
        Schema::dropIfExists('leaderboard_prize_rules');
    }
};
