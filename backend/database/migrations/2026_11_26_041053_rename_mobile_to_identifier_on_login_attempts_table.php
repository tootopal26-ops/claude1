<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in now accepts a username, mobile number or email — a login
 * attempt's target is no longer always a mobile number, so the column
 * that records it is renamed to match. The composite index on
 * [mobile, ip_address, created_at] is recreated against the renamed
 * column rather than relying on the rename carrying its name along
 * (safe either way, but explicit here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_attempts', function (Blueprint $table) {
            $table->renameColumn('mobile', 'identifier');
        });

        // renameColumn() carries the composite index along under its OLD
        // auto-generated name (still correctly indexing the renamed column) —
        // drop that stale name and recreate it under one that matches.
        Schema::table('login_attempts', function (Blueprint $table) {
            $table->dropIndex('login_attempts_mobile_ip_address_created_at_index');
            $table->index(['identifier', 'ip_address', 'created_at'], 'login_attempts_identifier_ip_address_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('login_attempts', function (Blueprint $table) {
            $table->dropIndex('login_attempts_identifier_ip_address_created_at_index');
        });

        Schema::table('login_attempts', function (Blueprint $table) {
            $table->renameColumn('identifier', 'mobile');
        });

        Schema::table('login_attempts', function (Blueprint $table) {
            $table->index(['mobile', 'ip_address', 'created_at'], 'login_attempts_mobile_ip_address_created_at_index');
        });
    }
};
