<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff accounts only (Super Admin, Event Manager, Volunteer).
     * Attendees are a separate identity domain — see docs/02 §2.1.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_users_ulid');
            $table->string('name', 150);
            $table->string('email', 190)->unique('uk_users_email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('password', 255);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->binary('last_login_ip')->nullable();
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'idx_users_status');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
