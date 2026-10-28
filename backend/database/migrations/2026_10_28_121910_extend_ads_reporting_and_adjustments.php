<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up to the ads tables:
 *  - a "between Home sections" banner placement (switched off, like every placement);
 *  - explicit, audited corrections to a credited Ads Earning allocation — a completed
 *    settlement is never edited; each correction is its own row and its own ledger entry;
 *  - the finalized revenue reports a settlement used, recorded on the batch for reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('ad_placements')->where('key', 'home_between')->exists()) {
            DB::table('ad_placements')->insert([
                'key' => 'home_between',
                'name' => 'Home — between sections',
                'format' => 'banner',
                'is_supported' => true,
                'is_enabled' => false,
                'insert_every' => null,
                'description' => 'A labelled banner between the Home status cards and the Esports Games grid.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('ad_settlement_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_settlement_allocation_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Signed: positive adds coins, negative takes them back.
            $table->decimal('coins', 12, 2);
            $table->string('reason', 255);
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->foreignId('created_by_staff_id')->constrained('staff_users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('ad_settlement_batches', function (Blueprint $table) {
            // [{id, period_start, period_end, revenue_usd, source}] as used when calculated.
            $table->json('revenue_sources')->nullable()->after('eligible_revenue_usd');
        });
    }

    public function down(): void
    {
        Schema::table('ad_settlement_batches', function (Blueprint $table) {
            $table->dropColumn('revenue_sources');
        });
        Schema::dropIfExists('ad_settlement_adjustments');
        DB::table('ad_placements')->where('key', 'home_between')->delete();
    }
};
