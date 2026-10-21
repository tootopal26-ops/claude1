<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_push_token_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['sent', 'failed', 'invalid_token'])->default('failed');
            $table->string('error_message')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['notification_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_delivery_logs');
    }
};
