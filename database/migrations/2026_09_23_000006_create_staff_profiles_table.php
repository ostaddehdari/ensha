<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('centres', function (Blueprint $t) { $t->string('phone', 20)->nullable(); $t->string('email', 190)->nullable(); $t->string('address', 500)->nullable(); $t->text('description')->nullable(); });
  Schema::create('staff_profiles', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete(); $t->string('job_title', 120)->nullable(); $t->string('employment_type', 30)->default('contract'); $t->date('start_date')->nullable(); $t->date('end_date')->nullable(); $t->string('license_number', 80)->nullable(); $t->string('specialty', 180)->nullable(); $t->text('internal_notes')->nullable(); $t->timestamps(); });
 }
 public function down(): void { Schema::dropIfExists('staff_profiles'); Schema::table('centres', fn (Blueprint $t) => $t->dropColumn(['phone','email','address','description'])); }
};
