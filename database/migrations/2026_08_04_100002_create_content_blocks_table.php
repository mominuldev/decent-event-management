<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_blocks', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_content_blocks_ulid');
            $table->foreignId('content_page_id')->constrained('content_pages')->cascadeOnDelete();

            // A closed set of typed blocks, not a page builder (docs/08 Phase
            // 3.5 "Scope boundary"). The allowed values live in
            // ContentBlock::TYPES; each type fixes the shape of `data`.
            $table->string('type', 32);
            $table->unsignedInteger('position')->default(0);

            $table->json('data');
            $table->json('data_bn')->nullable();

            $table->foreignId('media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['content_page_id', 'position'], 'idx_content_blocks_page_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_blocks');
    }
};
