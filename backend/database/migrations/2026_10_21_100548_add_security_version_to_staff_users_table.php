<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            // Bumped whenever this staff account's permission preset,
            // super-admin flag, active status, or password changes. The
            // staff guard's session stores the version seen at login time;
            // a mismatch forces re-authentication instead of letting a
            // revoked or re-permissioned session keep working until it
            // naturally expires.
            $table->unsignedInteger('security_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->dropColumn('security_version');
        });
    }
};
