<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('centre_booking_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->unique()->constrained('centres')->cascadeOnDelete();
            $table->boolean('check_rooms')->default(false);
            $table->boolean('allow_past_bookings')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centre_booking_policies');
    }
};
