<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile app no longer collects date of birth at registration (no
 * replacement 18+ check exists — see the Terms & Conditions and the
 * registration flow). Existing players' date_of_birth values are left
 * untouched; only new rows may now store NULL here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->change();
        });
    }

    /**
     * Not reversed: once any row holds a NULL date_of_birth, re-adding a
     * NOT NULL constraint without backfilling every existing NULL would
     * fail outright (or, if it somehow didn't, would be meaningless —
     * there is no real date of birth to recover for those players).
     * Deliberately a documented no-op rather than a command that either
     * errors or silently corrupts data.
     */
    public function down(): void
    {
        // Intentionally left blank — see the class docblock.
    }
};
