<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_ticket_types_ulid');
            $table->string('code', 16)->unique('uk_ticket_types_code');
            $table->string('name', 100);
            $table->string('name_bn', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('base_price_paisa');
            $table->unsignedBigInteger('additional_adult_price_paisa')->default(0);
            $table->unsignedBigInteger('additional_child_price_paisa')->default(0);
            $table->char('currency', 3)->default('BDT');
            $table->unsignedTinyInteger('base_admits');
            $table->unsignedTinyInteger('max_admits');
            $table->json('allowed_participant_types');
            $table->unsignedInteger('quantity_total')->nullable();
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->unsignedInteger('quantity_reserved')->default(0);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('includes_tshirt')->default(false);
            $table->boolean('includes_meal')->default(true);
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->string('badge_color', 9)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_public', 'sort_order'], 'idx_ticket_types_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
    }
};
