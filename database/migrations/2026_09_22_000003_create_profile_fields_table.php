<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_fields', function (Blueprint $table) {
            $table->id();
            $table->string('role', 40)->index();
            $table->string('label', 150);
            $table->string('key', 100);
            $table->string('field_type', 30)->default('text');
            $table->boolean('is_required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['role', 'key']);
        });

        Schema::create('profile_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_field_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'profile_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_values');
        Schema::dropIfExists('profile_fields');
    }
};
