<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique('uk_idem_key');
            $table->string('scope', 64);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->index('scope', 'idx_idem_scope');
            $table->index('expires_at', 'idx_idem_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
