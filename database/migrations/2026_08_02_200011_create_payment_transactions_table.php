<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_ptx_ulid');
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('direction', 16);
            $table->string('gateway', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('amount_paisa')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('gateway_reference', 120)->nullable();
            $table->string('gateway_transaction_id', 120)->nullable();
            $table->string('gateway_status_code', 32)->nullable();
            $table->string('gateway_message', 255)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->binary('ip_address')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique('uk_ptx_idem');
            $table->timestamp('created_at');

            $table->index(['payment_id', 'created_at'], 'idx_ptx_payment_created');
            $table->index('gateway_transaction_id', 'idx_ptx_gateway_txn');
            $table->index(['type', 'status'], 'idx_ptx_type_status');
            $table->index('created_at', 'idx_ptx_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
