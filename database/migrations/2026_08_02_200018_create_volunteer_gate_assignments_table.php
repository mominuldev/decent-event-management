<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_gate_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volunteer_profile_id')->constrained('volunteer_profiles')->cascadeOnDelete();
            $table->foreignId('gate_id')->constrained('gates')->cascadeOnDelete();
            $table->foreignId('event_session_id')->nullable()->constrained('event_sessions')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['volunteer_profile_id', 'gate_id', 'event_session_id'], 'uk_vga');
            $table->index('gate_id', 'idx_vga_gate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_gate_assignments');
    }
};
