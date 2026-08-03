<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_menus_ulid');

            // Stable handle the public site fetches by (`primary`, `footer`).
            $table->string('code', 32)->unique('uk_menus_code');
            $table->string('name', 120);
            $table->string('name_bn', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active', 'idx_menus_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
