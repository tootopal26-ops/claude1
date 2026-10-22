<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('content');
            $table->boolean('is_current')->default(false);
            $table->foreignId('published_by_staff_id')->constrained('staff_users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['type', 'version']);
            $table->index(['type', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
