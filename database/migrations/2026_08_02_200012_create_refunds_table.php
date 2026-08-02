<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_refunds_ulid');
            $table->string('refund_number', 32)->unique('uk_refunds_number');
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('registration_id')->constrained('registrations')->restrictOnDelete();
            $table->unsignedBigInteger('amount_paisa');
            $table->char('currency', 3)->default('BDT');
            $table->string('reason', 255);
            $table->string('type', 16);
            $table->string('method', 32);
            $table->string('status', 32);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('gateway_refund_id', 120)->nullable();
            $table->string('recipient_msisdn', 20)->nullable();
            $table->json('voided_ticket_ids')->nullable();
            $table->timestamps();

            $table->index('payment_id', 'idx_refunds_payment');
            $table->index('status', 'idx_refunds_status');
            $table->index('created_at', 'idx_refunds_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
