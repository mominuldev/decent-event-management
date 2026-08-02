<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_payments_ulid');
            $table->string('payment_number', 32)->unique('uk_payments_number');
            $table->foreignId('registration_id')->constrained('registrations')->restrictOnDelete();
            $table->foreignId('attendee_id')->constrained('attendees')->restrictOnDelete();
            $table->string('method', 32);
            $table->string('channel', 16);
            $table->string('status', 32);
            $table->unsignedBigInteger('amount_due_paisa');
            $table->unsignedBigInteger('amount_paid_paisa')->default(0);
            $table->unsignedBigInteger('fee_paisa')->default(0);
            $table->unsignedBigInteger('net_paisa')->default(0);
            $table->unsignedBigInteger('refunded_paisa')->default(0);
            $table->char('currency', 3)->default('BDT');
            $table->string('gateway_reference', 120)->nullable();
            $table->string('gateway_transaction_id', 120)->nullable();
            $table->string('payer_msisdn', 20)->nullable();
            $table->string('manual_trx_id', 64)->nullable();
            $table->foreignId('manual_proof_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->string('manual_sender_note', 255)->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_note', 255)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique('uk_payments_idem');
            $table->timestamp('reconciled_at')->nullable();
            $table->string('reconciliation_status', 32)->nullable();
            $table->timestamps();

            $table->index('registration_id', 'idx_payments_registration');
            $table->index(['status', 'created_at'], 'idx_payments_status_created');
            $table->index('gateway_transaction_id', 'idx_payments_gateway_txn');
            $table->index(['method', 'status'], 'idx_payments_method_status');
            $table->index('manual_trx_id', 'idx_payments_manual_trx');
            $table->index('paid_at', 'idx_payments_paid_at');
            $table->index(['reconciliation_status', 'reconciled_at'], 'idx_payments_reconciliation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
