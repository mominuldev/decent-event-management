<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table): void {
            // Nullable, and that is the whole design rather than an
            // oversight. Every attendee that predates this has no password,
            // and so does every one an admin creates or an import loads —
            // there is nothing truthful to backfill with, and a NOT NULL
            // column would force a fabricated credential onto 22,000 real
            // people. "No password yet" is a state the sign-in flow handles
            // (a one-time SMS code), not an error.
            $table->string('password')->nullable()->after('email');
            $table->timestamp('password_set_at')->nullable()->after('password');

            // Attempts against the current SMS code. A six-digit code is a
            // million guesses, which is nothing without a ceiling — this is
            // what makes it safe to send something short enough to fit one
            // SMS segment.
            $table->unsignedTinyInteger('auth_code_attempts')->default(0)->after('auth_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table): void {
            $table->dropColumn(['password', 'password_set_at', 'auth_code_attempts']);
        });
    }
};
