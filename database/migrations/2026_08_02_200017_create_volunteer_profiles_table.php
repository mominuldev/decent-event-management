<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_vp_ulid');
            $table->foreignId('user_id')->unique('uk_vp_user')->constrained('users')->cascadeOnDelete();
            $table->string('volunteer_code', 16)->unique('uk_vp_code');
            $table->string('pin_hash', 255)->nullable();
            $table->timestamp('pin_set_at')->nullable();
            $table->string('team', 64)->nullable();
            $table->timestamp('shift_starts_at')->nullable();
            $table->timestamp('shift_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_scans')->default(0);
            $table->timestamps();

            $table->index('is_active', 'idx_vp_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_profiles');
    }
};
