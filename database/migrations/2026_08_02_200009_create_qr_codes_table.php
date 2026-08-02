<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_qr_ulid');
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->unsignedTinyInteger('payload_version')->default(1);
            $table->string('payload', 255);
            $table->char('payload_hash', 64)->unique('uk_qr_payload_hash');
            $table->string('signature', 128);
            $table->string('signing_key_id', 32);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 255)->nullable();
            $table->foreignId('image_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamps();

            $table->index(['ticket_id', 'is_active'], 'idx_qr_ticket_active');
            $table->index('signing_key_id', 'idx_qr_signing_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
