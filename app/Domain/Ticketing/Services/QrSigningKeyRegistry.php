<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Ticketing\Models\QrSigningKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * Merges the two halves of QR key state: env (or a secret manager) holds
 * the private key material, the `qr_signing_keys` table holds which key is
 * active and which are published for verification.
 *
 * Splitting it this way is what docs/06 §6.5 requires — the private key
 * must never reach the database — and it also means the dangerous half of
 * a rotation (the flip to a new signing key) needs no deploy, while the
 * harmless half (making a new private key *available*) is an ordinary
 * config change that cannot break anything on its own.
 *
 * Falls back cleanly to pure-config behaviour when no rows exist, so an
 * environment that has never rotated behaves exactly as it did before this
 * table existed.
 */
class QrSigningKeyRegistry
{
    /**
     * The shape QrSigner consumes. Note `retired_public_keys` is really
     * "every other known public key" — pending keys go in it too, which is
     * precisely what publishes them to devices ahead of activation.
     *
     * @return array{active_key_id: string, active_private_key: ?string, retired_public_keys: array<string, string>}
     */
    public function resolve(): array
    {
        /** @var array{active_key_id?: string, active_private_key?: string, retired_public_keys?: array<string, string>} $config */
        $config = (array) config('services.qr_signing', []);

        $activeKeyId = (string) ($config['active_key_id'] ?? '');
        $otherPublicKeys = $config['retired_public_keys'] ?? [];

        // Every key this server holds private material for, whether or not
        // it has ever been registered. Without this, the very first rotation
        // silently un-publishes the incumbent key — it lives only in env, so
        // once the database names a different active key there is nothing
        // left pointing at it, and every ticket already printed stops
        // verifying at the gate. That is the exact failure docs/06 §6.5's
        // ordering exists to prevent, arriving through the back door.
        foreach ($this->availablePrivateKeyIds() as $availableKeyId) {
            $public = $this->publicKeyFor($availableKeyId);

            if ($public !== null) {
                $otherPublicKeys[$availableKeyId] ??= $public;
            }
        }

        $keys = $this->storedKeys();

        if ($keys !== null) {
            $active = $keys->firstWhere('status', QrSigningKey::ACTIVE);

            if ($active !== null) {
                $activeKeyId = $active->key_id;
            }

            foreach ($keys as $key) {
                $otherPublicKeys[$key->key_id] = $key->public_key;
            }
        }

        // The active key is deliberately NOT removed from this list even
        // though QrSigner also derives it from its own secret. QrSigner skips
        // any id it has already loaded, so it is redundant on a server that
        // holds the private half — and load-bearing on one that does not. An
        // instance mid-rolling-deploy, or a read-only replica, would
        // otherwise publish a manifest missing the active key's public half
        // entirely, and every device that synced from it would reject tickets
        // signed with that key.

        return [
            'active_key_id' => $activeKeyId,
            'active_private_key' => $this->privateKeyFor($activeKeyId),
            'retired_public_keys' => $otherPublicKeys,
        ];
    }

    /**
     * Base64 private key for a key id, or null when this server does not
     * hold it. Activation checks this so a key can never start signing on a
     * box that cannot sign with it.
     */
    public function privateKeyFor(string $keyId): ?string
    {
        $available = $this->availablePrivateKeys();

        return $available[$keyId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function availablePrivateKeyIds(): array
    {
        return array_keys($this->availablePrivateKeys());
    }

    /**
     * Derives the public half of a private key this server holds, so a key
     * can be registered without anybody copying key material through an API
     * request.
     */
    public function publicKeyFor(string $keyId): ?string
    {
        $secretB64 = $this->privateKeyFor($keyId);

        if ($secretB64 === null) {
            return null;
        }

        $secret = base64_decode($secretB64, true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }

        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    }

    /**
     * QR_SIGNING_PRIVATE_KEYS (a key_id => base64 map, for holding the next
     * key alongside the current one during a rotation) plus the original
     * single-key QR_SIGNING_KEY_ID/QR_SIGNING_PRIVATE_KEY pair, which stays
     * supported so no existing environment has to change to keep working.
     *
     * @return array<string, string>
     */
    private function availablePrivateKeys(): array
    {
        // Typed as mixed throughout on purpose: every value here comes from
        // an env var (one of them through json_decode), so nothing
        // guarantees the shape the rest of the config array declares.
        /** @var array<string, mixed> $config */
        $config = (array) config('services.qr_signing', []);

        $keys = [];

        /** @var array<mixed, mixed> $configured */
        $configured = is_array($config['private_keys'] ?? null) ? $config['private_keys'] : [];

        foreach ($configured as $keyId => $secret) {
            if (is_string($keyId) && is_string($secret) && $secret !== '') {
                $keys[$keyId] = $secret;
            }
        }

        $legacyKeyId = (string) ($config['active_key_id'] ?? '');
        $legacySecret = $config['active_private_key'] ?? null;

        if ($legacyKeyId !== '' && is_string($legacySecret) && $legacySecret !== '') {
            $keys[$legacyKeyId] ??= $legacySecret;
        }

        return $keys;
    }

    /**
     * Null — rather than an empty collection — when the table is not there
     * to be read. `IssueTicket` and `ProcessCheckIn` both construct a
     * QrSigner, and neither should explode during a deploy that has not run
     * migrations yet, or in a unit test with no database at all.
     *
     * Catching the failed query rather than asking Schema::hasTable() first
     * is deliberate: hasTable() hits information_schema on every call, and
     * this sits on the check-in path where a QrSigner is built per request.
     *
     * @return Collection<int, QrSigningKey>|null
     */
    private function storedKeys(): ?Collection
    {
        try {
            return QrSigningKey::query()->publishable()->get();
        } catch (QueryException) {
            return null;
        }
    }
}
