<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('entry_fee_paid', 10, 2)->default(0);
            $table->enum('status', ['confirmed', 'cancelled', 'refunded'])->default('confirmed');
            $table->timestamps();

            // One booking per user per tournament; the actual slot-capacity check
            // happens under a row lock in ConfirmSlotBookingAction, this constraint
            // only prevents the same user from double-booking the same tournament.
            $table->unique(['tournament_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_entries');
    }
};
