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
            $table->string('father_name', 150)->nullable();
            $table->string('mobile', 20)->unique('uk_attendees_mobile');
            $table->string('whatsapp_number', 20)->nullable();
            // Unique, like mobile: an address identifies exactly one person.
            // Both constraints cover soft-deleted rows — MySQL has no partial
            // index — so a deleted attendee keeps hold of their identifiers.
            // Blank is stored as NULL (many NULLs may share a unique index,
            // only one '' can), which every write path normalises.
            $table->string('email', 190)->nullable()->unique('uk_attendees_email');
            // Nullable: a self-registered attendee chooses one at checkout,
            // a counter-registered one has none until they set it.
            $table->string('password')->nullable();
            $table->timestamp('password_set_at')->nullable();
            // VARCHAR(32), not 16: 'prefer_not_to_say' alone is 18 characters.
            $table->string('gender', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            // NID (10/13/17 digits) or birth registration number (16/17),
            // stored as digits only. No unique index: the field is optional
            // and one mistyped digit would collide with a stranger.
            $table->string('nid_number', 32)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->string('designation', 120)->nullable();
            $table->string('organization', 150)->nullable();
            $table->string('participant_type', 32);
            $table->unsignedSmallInteger('ssc_batch_year')->nullable();
            $table->string('current_class', 32)->nullable();
            $table->foreignId('profile_photo_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->boolean('tshirt_required')->default(false);
            $table->string('tshirt_size', 8)->nullable();
            // Free text, not pickers — a Bangladeshi address is prose, and a
            // bundled upazila/post-office table would eventually refuse a
            // place that exists. Ordered as the address is written.
            $table->string('address_district', 80)->nullable();
            $table->string('current_address', 255)->nullable();
            $table->string('post_office', 100)->nullable();
            $table->string('upazila', 100)->nullable();
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
            // Wrong guesses at the sign-in code; the code is burned at five.
            $table->unsignedTinyInteger('auth_code_attempts')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

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
