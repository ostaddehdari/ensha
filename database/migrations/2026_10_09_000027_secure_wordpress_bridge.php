<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wordpress_request_nonces')) {
            Schema::create('wordpress_request_nonces', function (Blueprint $table) {
                $table->id();
                $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
                $table->string('nonce', 128);
                $table->unsignedBigInteger('request_timestamp');
                $table->timestamp('expires_at')->index();
                $table->timestamp('created_at');
                $table->unique(['centre_id', 'nonce']);
            });
        }

        if (! Schema::hasTable('wordpress_idempotency_keys')) {
            Schema::create('wordpress_idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->string('method', 10);
                $table->string('path', 500);
                $table->unsignedSmallInteger('status_code')->nullable();
                $table->longText('response_body')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
                $table->unique(['centre_id', 'idempotency_key'], 'wp_idempotency_centre_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_idempotency_keys');
        Schema::dropIfExists('wordpress_request_nonces');
    }
};
