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
        Schema::create('payment_provider_configs', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->boolean('is_active')->default(true);

            // Admin-overridable because the provider's own documentation is
            // internally inconsistent about this header's real name (prose
            // says one thing, the executable sample reads another) — this
            // must be confirmed against a real webhook delivery, and this
            // column lets staff correct it without a code deploy.
            $table->string('webhook_signature_header')->default('X-PayIndia-Signature');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_provider_configs');
    }
};
