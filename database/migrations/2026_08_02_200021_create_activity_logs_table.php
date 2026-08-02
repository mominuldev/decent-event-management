<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_al_ulid');
            $table->string('log_name', 32);
            $table->string('event', 64);
            $table->string('description', 255);
            $table->string('causer_type', 32)->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('properties')->nullable();
            $table->binary('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('request_id', 26)->nullable();
            $table->string('severity', 16)->default('info');
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id', 'created_at'], 'idx_al_subject');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'idx_al_causer');
            $table->index(['log_name', 'event'], 'idx_al_name_event');
            $table->index('created_at', 'idx_al_created');
            $table->index(['severity', 'created_at'], 'idx_al_severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
