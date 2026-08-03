<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only revision history (docs/08 Phase 3.5 — "content_pages rows
     * are versioned, never overwritten in place"). Every save snapshots the
     * page's editable fields plus its full block tree, so a restore needs no
     * join against live data. Rows are never updated or deleted, hence
     * `created_at` only and no `updated_at`.
     */
    public function up(): void
    {
        Schema::create('content_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_content_revisions_ulid');
            $table->foreignId('content_page_id')->constrained('content_pages')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');

            $table->string('title', 190);
            $table->string('title_bn', 190)->nullable();
            $table->text('excerpt')->nullable();
            $table->text('excerpt_bn')->nullable();
            $table->string('seo_title', 190)->nullable();
            $table->string('seo_title_bn', 190)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->string('seo_description_bn', 255)->nullable();

            // Full block tree at capture time, so restoring a revision never
            // depends on blocks that may since have been deleted.
            $table->json('blocks_snapshot');

            $table->string('status_at_capture', 16);
            $table->string('change_note', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['content_page_id', 'revision_number'], 'uk_content_revisions_page_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_page_revisions');
    }
};
