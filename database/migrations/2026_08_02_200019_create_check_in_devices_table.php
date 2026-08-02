<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_in_devices', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_dev_ulid');
            $table->string('device_code', 16)->unique('uk_dev_code');
            $table->string('device_name', 100);
            $table->string('device_fingerprint', 190)->unique('uk_dev_fingerprint');
            $table->string('platform', 16);
            $table->string('app_version', 20)->nullable();
            $table->string('os_version', 32)->nullable();
            $table->foreignId('assigned_volunteer_profile_id')->nullable()->constrained('volunteer_profiles')->nullOnDelete();
            $table->unsignedBigInteger('sanctum_token_id')->nullable();
            $table->string('status', 32);
            $table->foreignId('enrolled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('manifest_version')->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('pending_scan_count')->default(0);
            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->unsignedInteger('total_scans')->default(0);
            $table->timestamps();

            $table->index('status', 'idx_dev_status');
            $table->index('last_sync_at', 'idx_dev_last_sync');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_devices');
    }
};
