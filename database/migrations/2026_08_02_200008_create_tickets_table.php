<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_tickets_ulid');
            $table->string('ticket_number', 40)->unique('uk_tickets_number');
            $table->foreignId('registration_id')->constrained('registrations')->restrictOnDelete();
            $table->foreignId('attendee_id')->constrained('attendees')->restrictOnDelete();
            $table->foreignId('ticket_type_id')->constrained('ticket_types')->restrictOnDelete();
            $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->restrictOnDelete();
            $table->string('status', 32);
            $table->unsignedTinyInteger('admits_total');
            $table->unsignedTinyInteger('admitted_count')->default(0);
            $table->unsignedBigInteger('price_paid_paisa');
            $table->char('currency', 3)->default('BDT');
            $table->string('holder_name', 150);
            $table->unsignedSmallInteger('holder_batch_year')->nullable();
            $table->string('holder_type_label', 64);
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('replaces_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamp('first_admitted_at')->nullable();
            $table->timestamp('last_admitted_at')->nullable();
            $table->foreignId('pdf_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->unsignedInteger('manifest_version')->default(1);
            $table->timestamps();

            $table->index('registration_id', 'idx_tickets_registration');
            $table->index('attendee_id', 'idx_tickets_attendee');
            $table->index('status', 'idx_tickets_status');
            $table->index(['ticket_type_id', 'status'], 'idx_tickets_type_status');
            $table->index('manifest_version', 'idx_tickets_manifest');
            $table->index(['event_session_id', 'status'], 'idx_tickets_session_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
