<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_sessions_ulid');
            $table->string('code', 32)->unique('uk_sessions_code');
            $table->string('name', 120);
            $table->string('venue', 190)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('checkin_opens_at');
            $table->timestamp('checkin_closes_at');
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('admitted_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_at'], 'idx_sessions_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sessions');
    }
};
