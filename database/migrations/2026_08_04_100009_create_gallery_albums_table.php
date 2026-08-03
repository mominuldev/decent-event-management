<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_albums', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_gallery_albums_ulid');
            $table->string('slug', 160)->unique('uk_gallery_albums_slug');

            $table->string('title', 190);
            $table->string('title_bn', 190)->nullable();
            $table->text('description')->nullable();
            $table->text('description_bn')->nullable();

            $table->foreignId('cover_media_id')->nullable()->constrained('media_files')->nullOnDelete();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['is_published', 'position'], 'idx_gallery_albums_published');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_albums');
    }
};
