<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Generated for every player at registration regardless of whether the
            // referral system is enabled (see User::booted()) — a harmless account
            // attribute, not itself a reward trigger.
            $table->string('referral_code', 12)->nullable()->unique()->after('state');
            // Set once, at registration, and never changed afterward — the referral
            // relationship a signup used, if any. Null for every account that signed up
            // without a valid code (including every account that existed before this
            // feature shipped).
            $table->foreignId('referred_by_user_id')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn('referral_code');
        });
    }
};
