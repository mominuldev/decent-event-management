<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendees', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_attendees_ulid');
            $table->string('full_name', 150);
            $table->string('full_name_bn', 150)->nullable();
            $table->string('mobile', 20)->unique('uk_attendees_mobile');
            $table->string('whatsapp_number', 20)->nullable();
            $table->string('email', 190)->nullable();
            // VARCHAR(32), not 16: 'prefer_not_to_say' alone is 18 characters.
            $table->string('gender', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('occupation', 120)->nullable();
            $table->string('designation', 120)->nullable();
            $table->string('organization', 150)->nullable();
            $table->string('participant_type', 32);
            $table->unsignedSmallInteger('ssc_batch_year')->nullable();
            $table->string('current_class', 32)->nullable();
            $table->foreignId('profile_photo_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->boolean('tshirt_required')->default(false);
            $table->string('tshirt_size', 8)->nullable();
            $table->string('address_district', 80)->nullable();
            $table->char('country', 2)->default('BD');
            $table->string('blood_group', 8)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('merged_into_attendee_id')->nullable()->constrained('attendees')->nullOnDelete();
            $table->string('auth_token_hash', 255)->nullable();
            $table->timestamp('auth_token_expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('email', 'idx_attendees_email');
            $table->index(['ssc_batch_year', 'participant_type'], 'idx_attendees_batch_type');
            $table->index('participant_type', 'idx_attendees_participant_type');
            $table->index(['tshirt_required', 'tshirt_size'], 'idx_attendees_tshirt');
            $table->index('full_name', 'idx_attendees_name');
            $table->index('merged_into_attendee_id', 'idx_attendees_merged');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendees');
    }
};
