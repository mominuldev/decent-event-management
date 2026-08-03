<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_items', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_gallery_items_ulid');
            $table->foreignId('gallery_album_id')->constrained('gallery_albums')->cascadeOnDelete();

            // An item without its file is meaningless, so the media row is
            // restricted from deletion rather than nulled.
            $table->foreignId('media_id')->constrained('media_files')->restrictOnDelete();

            $table->string('caption', 255)->nullable();
            $table->string('caption_bn', 255)->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->string('alt_text_bn', 255)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['gallery_album_id', 'is_published', 'position'], 'idx_gallery_items_album');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_items');
    }
};
