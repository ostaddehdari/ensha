<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('profile_fields', function(Blueprint $t) { $t->json('settings')->nullable(); }); }
 public function down(): void { Schema::table('profile_fields', fn(Blueprint $t)=>$t->dropColumn('settings')); }
};
