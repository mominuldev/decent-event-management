<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_content_pages_ulid');
            $table->string('slug', 160)->unique('uk_content_pages_slug');
            $table->string('template', 32)->default('standard');

            // Bilingual by construction (docs/08 Phase 3.5) — every editable
            // string carries an `_bn` sibling rather than duplicating the page
            // tree per locale. `_bn` is nullable so an editor can publish
            // English first; readers fall back to English when it is empty.
            $table->string('title', 190);
            $table->string('title_bn', 190)->nullable();
            $table->text('excerpt')->nullable();
            $table->text('excerpt_bn')->nullable();
            $table->string('seo_title', 190)->nullable();
            $table->string('seo_title_bn', 190)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->string('seo_description_bn', 255)->nullable();
            $table->foreignId('og_image_media_id')->nullable()->constrained('media_files')->nullOnDelete();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();

            // Single-use-ish shared secret that reveals unpublished content to
            // a reviewer. Never exposed on a published-content response.
            $table->char('preview_token', 32)->nullable()->unique('uk_content_pages_preview_token');

            $table->boolean('is_indexable')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('revision_number')->default(1);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The public read path is always "published, and scheduled time
            // has arrived" — this composite serves it directly.
            $table->index(['status', 'published_at'], 'idx_content_pages_status_published');
            $table->index('position', 'idx_content_pages_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
