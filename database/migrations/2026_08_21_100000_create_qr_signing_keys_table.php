<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The lifecycle of a QR signing key — deliberately NOT the key itself.
 *
 * docs/06 §6.5 is explicit that the private key lives in the secret
 * manager and "never in the repository, the database, or environment
 * files committed anywhere". So this table holds only the *public* half
 * plus the rotation state machine and its audit trail; the private half
 * stays in QR_SIGNING_PRIVATE_KEY(S), and activating a key that has no
 * private half available fails closed.
 *
 * What this buys over hand-editing .env in the right order: the ordering
 * docs/06 §6.5 requires (publish → confirm every device synced → only
 * then sign with it) becomes an enforced gate rather than a checklist,
 * and the flip itself needs no deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_signing_keys', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique('uk_qsk_ulid');

            // Matches qr_codes.signing_key_id (varchar 32) — the value that
            // travels inside every QR payload.
            $table->string('key_id', 32)->unique('uk_qsk_key_id');
            $table->string('public_key', 64);

            // pending  = published to devices, not yet signing
            // active   = signing new tickets (at most one row, enforced below)
            // retired  = no longer signs, still verifies tickets in circulation
            $table->string('status', 32);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();

            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retired_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status', 'idx_qsk_status');
        });

        // Two active signing keys is not a degraded state, it is an
        // ambiguous one — which key signs is then a race. A generated
        // column plus a unique index makes the database refuse it outright,
        // rather than relying on every write path to remember.
        DB::statement(<<<'SQL'
            ALTER TABLE qr_signing_keys
            ADD COLUMN active_singleton TINYINT
                GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 1 ELSE NULL END) STORED,
            ADD UNIQUE KEY uk_qsk_single_active (active_singleton)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_signing_keys');
    }
};
