<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_items', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_schedule_items_ulid');

            $table->string('title', 190);
            $table->string('title_bn', 190)->nullable();
            $table->text('description')->nullable();
            $table->text('description_bn')->nullable();

            $table->string('speaker_name', 150)->nullable();
            $table->string('speaker_name_bn', 150)->nullable();
            $table->string('speaker_title', 150)->nullable();
            $table->string('speaker_title_bn', 150)->nullable();
            $table->foreignId('speaker_photo_media_id')->nullable()->constrained('media_files')->nullOnDelete();

            $table->string('venue', 190)->nullable();
            $table->string('venue_bn', 190)->nullable();
            $table->string('track', 32)->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            // Soft reference to CheckIn's `event_sessions.code`, deliberately
            // not a foreign key: the module-boundary rule (CLAUDE.md) forbids
            // Content reaching into another module's tables. A published
            // schedule entry is marketing copy and must survive a session
            // being renamed or removed.
            $table->string('event_session_code', 32)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['is_published', 'starts_at'], 'idx_schedule_published_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_items');
    }
};
