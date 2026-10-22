<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a risk alert point at the specific record it's about (a deposit
 * order, a withdrawal, a tournament, ...), not just the target user.
 * Phase 13 uses this to decide which storage_assets count as evidence
 * tied to an active (unreviewed) dispute/audit: any asset whose own
 * subject matches an open risk alert's subject is exempt from cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_alerts', function (Blueprint $table) {
            $table->string('subject_type')->nullable()->after('target_user_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('risk_alerts', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
