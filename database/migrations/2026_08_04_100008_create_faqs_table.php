<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_faqs_ulid');

            $table->string('question', 255);
            $table->string('question_bn', 255)->nullable();
            $table->text('answer');
            $table->text('answer_bn')->nullable();

            $table->string('category', 48)->nullable();
            $table->string('category_bn', 48)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['is_published', 'category', 'position'], 'idx_faqs_published_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
