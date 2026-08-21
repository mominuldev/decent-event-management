<?php

namespace App\Domain\Ticketing\Actions;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Exceptions\KeyRotationException;
use App\Domain\Ticketing\Models\QrSigningKey;
use App\Domain\Ticketing\Services\QrSigningKeyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of docs/06 §6.5's rotation: publish the new public key to devices.
 *
 * No key material crosses the API. The caller names a key_id that this
 * server already holds the private half of (via QR_SIGNING_PRIVATE_KEYS or
 * the secret manager behind it) and the public half is *derived* here — so
 * a published key is, by construction, one this server can later sign with,
 * and an operator cannot fat-finger a public key that does not match.
 *
 * Publishing is safe on its own: the key enters the manifest's `meta.keys`
 * so devices learn to verify it, but nothing signs with it until it is
 * activated.
 */
class PublishQrSigningKey
{
    public function __construct(
        private readonly QrSigningKeyRegistry $registry,
    ) {}

    public function execute(string $keyId, User $publishedBy, ?string $ip = null, ?string $requestId = null): QrSigningKey
    {
        return DB::transaction(function () use ($keyId, $publishedBy, $ip, $requestId): QrSigningKey {
            // Adoption runs *before* the duplicate check, not after it, so
            // that asking to publish the key which is already signing is
            // caught by that check and answers 422 "already registered".
            // The other order inserts the incumbent and then tries to insert
            // it again, which is a unique-constraint 500 — found by calling
            // the real endpoint, not by any test, because every fixture had a
            // next key distinct from the incumbent.
            //
            // Throwing below rolls the adoption back with it, so a refused
            // publish leaves the table exactly as it found it.
            $this->adoptIncumbentKey();

            if (QrSigningKey::query()->where('key_id', $keyId)->exists()) {
                throw KeyRotationException::alreadyRegistered($keyId);
            }

            $publicKey = $this->registry->publicKeyFor($keyId);

            if ($publicKey === null) {
                throw KeyRotationException::noPrivateKeyAvailable($keyId);
            }

            $key = QrSigningKey::create([
                'key_id' => $keyId,
                'public_key' => $publicKey,
                'status' => QrSigningKey::PENDING,
                'published_at' => now(),
                'published_by_user_id' => $publishedBy->id,
            ]);

            ActivityLog::create([
                'log_name' => 'qr_signing',
                'event' => 'key_published',
                'description' => "Published QR signing key '{$keyId}' to scanner devices",
                'causer_type' => $publishedBy->getMorphClass(),
                'causer_id' => $publishedBy->id,
                'subject_type' => $key->getMorphClass(),
                'subject_id' => $key->id,
                'properties' => [
                    'key_id' => $keyId,
                    // The public key is not a secret — recording it is what
                    // lets an incident review confirm which key material was
                    // in circulation on a given date.
                    'public_key' => $publicKey,
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            return $key;
        });
    }

    /**
     * Give the key that is *currently* signing a database row, if it does
     * not have one, before publishing its replacement.
     *
     * Until the first rotation the incumbent exists only in env, so the
     * table starts empty. Registering it here means the activation step has
     * something to retire, the console shows the real before-and-after
     * rather than a rotation appearing out of nowhere, and the audit trail
     * names both keys. It is recorded as already-active and already-
     * published because that is the truth: devices have been verifying with
     * it since before this table existed.
     */
    private function adoptIncumbentKey(): void
    {
        $incumbentId = (string) config('services.qr_signing.active_key_id', '');

        if ($incumbentId === '' || QrSigningKey::query()->where('key_id', $incumbentId)->exists()) {
            return;
        }

        // An active row already present under a different id means this
        // environment has rotated before and env is simply stale; the
        // database is authoritative, so leave it alone.
        if (QrSigningKey::query()->active()->exists()) {
            return;
        }

        $publicKey = $this->registry->publicKeyFor($incumbentId);

        if ($publicKey === null) {
            return;
        }

        QrSigningKey::create([
            'key_id' => $incumbentId,
            'public_key' => $publicKey,
            'status' => QrSigningKey::ACTIVE,
            'published_at' => now(),
            'activated_at' => now(),
        ]);
    }
}
