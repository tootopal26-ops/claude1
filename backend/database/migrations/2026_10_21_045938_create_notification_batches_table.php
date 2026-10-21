<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->enum('audience_type', ['all', 'selected_users', 'tournament_participants']);
            $table->json('audience_meta')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('push_sent_count')->default(0);
            $table->unsignedInteger('push_failed_count')->default(0);
            $table->string('idempotency_key')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_batches');
    }
};
