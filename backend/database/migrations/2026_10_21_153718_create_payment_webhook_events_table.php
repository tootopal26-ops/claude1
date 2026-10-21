<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('order_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamps();

            // Alpha UPI's documented webhook payload carries no dedicated
            // event id, so (provider, order_id, event_type) is the dedup
            // key: a retried delivery of the same event hits this unique
            // index and is treated as already-processed, not re-applied.
            $table->unique(['provider', 'order_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
