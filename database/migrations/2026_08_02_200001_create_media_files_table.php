<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_media_ulid');
            $table->string('collection', 32);
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('original_name', 190)->nullable();
            $table->string('mime_type', 100);
            $table->string('extension', 12);
            $table->unsignedInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('scan_status', 16)->default('pending');
            $table->timestamp('scanned_at')->nullable();
            $table->string('uploaded_by_type', 32)->nullable();
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('collection', 'idx_media_collection');
            $table->index('checksum_sha256', 'idx_media_checksum');
            $table->index('scan_status', 'idx_media_scan');
            $table->index('expires_at', 'idx_media_expires');
            $table->index(['uploaded_by_type', 'uploaded_by_id'], 'idx_media_uploader');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
