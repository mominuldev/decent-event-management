<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_notif_ulid');
            $table->string('notifiable_type', 64);
            $table->unsignedBigInteger('notifiable_id');
            $table->foreignId('attendee_id')->nullable()->constrained('attendees')->nullOnDelete();
            $table->string('template_key', 64);
            $table->string('channel', 16);
            $table->string('locale', 8)->default('en');
            $table->string('recipient', 190);
            $table->string('subject', 190)->nullable();
            $table->text('body_rendered')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('attachment_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->string('status', 32);
            $table->unsignedTinyInteger('priority')->default(3);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->unsignedTinyInteger('segment_count')->nullable();
            $table->unsignedInteger('cost_paisa')->nullable();
            $table->string('dedupe_key', 190)->nullable()->unique('uk_notif_dedupe');
            $table->timestamps();

            $table->index(['status', 'scheduled_for'], 'idx_notif_status_scheduled');
            $table->index(['notifiable_type', 'notifiable_id'], 'idx_notif_notifiable');
            $table->index('attendee_id', 'idx_notif_attendee');
            $table->index(['channel', 'status'], 'idx_notif_channel_status');
            $table->index('provider_message_id', 'idx_notif_provider_msg');
            $table->index('created_at', 'idx_notif_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
