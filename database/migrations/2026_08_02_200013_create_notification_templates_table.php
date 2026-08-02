<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->string('channel', 16);
            $table->string('locale', 8);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('subject', 190)->nullable();
            $table->text('body');
            $table->string('whatsapp_template_name', 100)->nullable();
            $table->string('whatsapp_template_status', 32)->nullable();
            $table->json('variables')->nullable();
            $table->unsignedTinyInteger('estimated_segments')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['key', 'channel', 'locale', 'version'], 'uk_tpl_key_channel_locale_version');
            $table->index('is_active', 'idx_tpl_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
