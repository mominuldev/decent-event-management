<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique('uk_settings_key');
            $table->string('group', 32);
            $table->text('value')->nullable();
            $table->string('type', 16);
            $table->boolean('is_encrypted')->default(false);
            $table->boolean('is_public')->default(false);
            $table->string('label', 120);
            $table->string('description', 255)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('group', 'idx_settings_group');
            $table->index('is_public', 'idx_settings_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_settings');
    }
};
