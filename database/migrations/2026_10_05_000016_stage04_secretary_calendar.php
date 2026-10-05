<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->string('default_view', 12)->default('week');
            $table->unsignedSmallInteger('day_start_hour')->default(8);
            $table->unsignedSmallInteger('day_end_hour')->default(21);
            $table->json('filters')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'centre_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('calendar_preferences'); }
};
