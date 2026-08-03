<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsors', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_sponsors_ulid');
            $table->string('name', 190);
            $table->string('name_bn', 190)->nullable();

            // Ordering weight lives in Sponsor::TIERS, not in the column, so
            // adding a tier does not need a migration.
            $table->string('tier', 24)->default('partner');

            $table->foreignId('logo_media_id')->nullable()->constrained('media_files')->nullOnDelete();
            $table->string('website_url', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('description_bn')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['is_published', 'tier', 'position'], 'idx_sponsors_published_tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsors');
    }
};
