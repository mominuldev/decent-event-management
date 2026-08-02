<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_re_ulid');
            $table->string('report_key', 64);
            $table->string('format', 8);
            $table->json('filters')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('row_count')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_re_status');
            $table->index(['requested_by_user_id', 'created_at'], 'idx_re_requester');
            $table->index('expires_at', 'idx_re_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
