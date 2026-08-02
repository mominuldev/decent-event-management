<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('event', 32);
            $table->string('provider_status', 64)->nullable();
            $table->string('detail', 500)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['notification_id', 'occurred_at'], 'idx_ne_notification');
            $table->index('event', 'idx_ne_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
