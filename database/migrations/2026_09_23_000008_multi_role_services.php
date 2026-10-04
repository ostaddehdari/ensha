<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('user_role_centres', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('centre_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamps(); $t->unique(['user_id','role_id','centre_id']);
        });
        DB::table('users')->whereNotNull('role_id')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $u) DB::table('user_role_centres')->insert(['user_id'=>$u->id,'role_id'=>$u->role_id,'centre_id'=>$u->centre_id,'created_at'=>now(),'updated_at'=>now()]);
        });
        Schema::table('users', fn(Blueprint $t) => $t->string('avatar_path')->nullable());
        Schema::create('service_categories', function(Blueprint $t) {
            $t->id(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete(); $t->string('name',120); $t->timestamps(); $t->unique(['centre_id','name']);
        });
        Schema::create('service_topics', function(Blueprint $t) {
            $t->id(); $t->foreignId('category_id')->constrained('service_categories')->cascadeOnDelete(); $t->string('name',120);
            $t->unsignedSmallInteger('minimum_minutes'); $t->unsignedSmallInteger('session_minutes'); $t->unsignedBigInteger('price'); $t->char('color',7); $t->timestamps(); $t->unique(['category_id','name']);
        });
        Schema::create('counselor_topics', function(Blueprint $t) { $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->foreignId('topic_id')->constrained('service_topics')->cascadeOnDelete(); $t->primary(['user_id','topic_id']); });
        Schema::create('counselor_shifts', function(Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday'); $t->time('starts_at'); $t->time('ends_at'); $t->unsignedBigInteger('hourly_pay')->default(0); $t->timestamps(); $t->index(['user_id','weekday']);
        });
        Schema::create('centre_closures', function(Blueprint $t) { $t->id(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete(); $t->date('starts_on'); $t->date('ends_on'); $t->string('reason',200); $t->timestamps(); });
        Schema::create('counselor_leaves', function(Blueprint $t) { $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete(); $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->string('reason',200)->nullable(); $t->timestamps(); });
        Schema::create('centre_rooms', function(Blueprint $t) { $t->id(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete(); $t->string('name',120); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('shift_change_logs', function(Blueprint $t) { $t->id(); $t->foreignId('shift_id')->nullable()->constrained('counselor_shifts')->nullOnDelete(); $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->foreignId('centre_id')->constrained()->cascadeOnDelete(); $t->json('before')->nullable(); $t->json('after')->nullable(); $t->timestamp('created_at'); });
    }
    public function down(): void {
        foreach (['shift_change_logs','centre_rooms','counselor_leaves','centre_closures','counselor_shifts','counselor_topics','service_topics','service_categories','user_role_centres'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn('avatar_path'));
    }
};
