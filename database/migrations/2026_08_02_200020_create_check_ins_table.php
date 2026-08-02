<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_ci_ulid');
            $table->char('client_scan_uuid', 36)->unique('uk_ci_client_uuid');
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained('registrations')->restrictOnDelete();
            $table->foreignId('attendee_id')->nullable()->constrained('attendees')->restrictOnDelete();
            $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->restrictOnDelete();
            $table->foreignId('gate_id')->nullable()->constrained('gates')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('check_in_devices')->nullOnDelete();
            $table->foreignId('scanned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result', 32);
            $table->string('rejection_detail', 255)->nullable();
            $table->unsignedTinyInteger('admitted_count')->default(0);
            $table->json('admitted_guest_ids')->nullable();
            $table->string('raw_payload', 255)->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->string('scan_mode', 16);
            $table->boolean('is_manual_override')->default(false);
            $table->foreignId('override_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('override_reason', 255)->nullable();
            $table->boolean('conflict_flag')->default(false);
            $table->timestamp('conflict_resolved_at')->nullable();
            $table->foreignId('conflict_resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at', 3);
            $table->timestamp('synced_at')->nullable();
            $table->integer('device_clock_skew_ms')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('created_at');

            $table->index(['ticket_id', 'scanned_at'], 'idx_ci_ticket_scanned');
            $table->index('result', 'idx_ci_result');
            $table->index(['gate_id', 'scanned_at'], 'idx_ci_gate_scanned');
            $table->index(['device_id', 'scanned_at'], 'idx_ci_device');
            $table->index('scanned_by_user_id', 'idx_ci_scanned_by');
            $table->index(['conflict_flag', 'conflict_resolved_at'], 'idx_ci_conflict');
            $table->index(['event_session_id', 'result'], 'idx_ci_session_result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
