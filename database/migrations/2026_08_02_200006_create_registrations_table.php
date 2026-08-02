<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_registrations_ulid');
            $table->string('registration_number', 32)->unique('uk_registrations_number');
            $table->foreignId('attendee_id')->constrained('attendees')->restrictOnDelete();
            $table->foreignId('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->restrictOnDelete();
            $table->string('participation_type', 16);
            $table->unsignedTinyInteger('adults_count')->default(1);
            $table->unsignedTinyInteger('children_count')->default(0);
            $table->unsignedTinyInteger('total_persons')->storedAs('adults_count + children_count');
            $table->string('status', 32);
            $table->unsignedBigInteger('subtotal_paisa');
            $table->unsignedBigInteger('discount_paisa')->default(0);
            $table->unsignedBigInteger('total_paisa');
            $table->char('currency', 3)->default('BDT');
            $table->string('discount_code', 32)->nullable();
            $table->text('comments')->nullable();
            $table->text('special_notes')->nullable();
            $table->string('source', 32);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->binary('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('attendee_id', 'idx_reg_attendee');
            $table->index(['status', 'created_at'], 'idx_reg_status_created');
            $table->index('ticket_type_id', 'idx_reg_ticket_type');
            $table->index('participation_type', 'idx_reg_participation');
            $table->index('confirmed_at', 'idx_reg_confirmed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
