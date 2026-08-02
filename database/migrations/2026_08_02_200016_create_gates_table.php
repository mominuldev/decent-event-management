<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gates', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_gates_ulid');
            $table->string('code', 16)->unique('uk_gates_code');
            $table->string('name', 100);
            $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->nullOnDelete();
            $table->json('allowed_ticket_type_ids')->nullable();
            $table->string('location_note', 190)->nullable();
            $table->unsignedInteger('admitted_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('event_session_id', 'idx_gates_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gates');
    }
};
