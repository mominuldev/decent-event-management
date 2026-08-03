<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_menu_items_ulid');
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();

            // One level of nesting is what the public site renders; the
            // self-reference is not restricted further at the schema level.
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();

            $table->string('label', 120);
            $table->string('label_bn', 120)->nullable();

            // Either an internal page reference or an external/absolute URL.
            // MenuItem::resolvedUrl() prefers the page when both are set, so a
            // renamed slug never leaves a dead link behind.
            $table->foreignId('content_page_id')->nullable()->constrained('content_pages')->nullOnDelete();
            $table->string('url', 255)->nullable();

            $table->string('target', 12)->default('_self');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'position'], 'idx_menu_items_tree');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
