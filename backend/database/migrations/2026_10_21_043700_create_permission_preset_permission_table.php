<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_preset_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_preset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['permission_preset_id', 'permission_id'], 'preset_permission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_preset_permission');
    }
};
