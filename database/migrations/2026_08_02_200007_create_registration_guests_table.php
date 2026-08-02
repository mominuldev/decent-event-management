<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_guests', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_guests_ulid');
            $table->foreignId('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->string('relation', 32)->nullable();
            $table->string('age_group', 16);
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('gender', 32)->nullable();
            $table->boolean('tshirt_required')->default(false);
            $table->string('tshirt_size', 8)->nullable();
            $table->boolean('is_admitted')->default(false);
            $table->timestamp('admitted_at')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('registration_id', 'idx_guests_registration');
            $table->index(['tshirt_required', 'tshirt_size'], 'idx_guests_tshirt');
            $table->index('age_group', 'idx_guests_age_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_guests');
    }
};
