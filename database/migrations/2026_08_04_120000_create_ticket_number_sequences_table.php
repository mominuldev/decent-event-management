<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_type_id')->constrained('ticket_types')->cascadeOnDelete();
            // Mirrors the batch segment baked into `ticket_number` itself
            // (e.g. "1998", or "XXXX" when the holder has no batch year) —
            // a string, not a nullable year, so the unique index below
            // can't be defeated by MySQL treating every NULL as distinct.
            $table->string('batch_label', 10);
            $table->unsignedInteger('seq')->default(0);
            $table->timestamps();

            $table->unique(['ticket_type_id', 'batch_label'], 'uk_ticket_seq_type_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_number_sequences');
    }
};
