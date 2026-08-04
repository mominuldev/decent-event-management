<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Ticketing\Support\QrPayload;
use RuntimeException;

/**
 * The only code path that touches the QR signing private key (docs/06
 * §6.5). Ed25519 via libsodium: the server signs, scanner devices verify
 * with the public key alone — a compromised device cannot forge a ticket.
 *
 * Supports multiple simultaneously-valid keys so a rotation doesn't
 * invalidate tickets signed under the previous key: `active_key_id`'s
 * key signs new tickets, every key in `keys` (including retired ones,
 * public component only) verifies.
 */
class QrSigner
{
    private readonly string $activeKeyId;

    /** @var array<string, array{public: string, secret: ?string}> key_id => raw (binary) key material */
    private readonly array $keys;

    /**
     * @param  array{active_key_id?: string, active_private_key?: string, retired_public_keys?: array<string, string>}|null  $config
     */
    public function __construct(?array $config = null)
    {
        $config ??= (array) config('services.qr_signing', []);

        $this->activeKeyId = (string) ($config['active_key_id'] ?? '');
        $keys = [];

        $activePrivateKeyB64 = $config['active_private_key'] ?? null;
        if ($this->activeKeyId !== '' && is_string($activePrivateKeyB64) && $activePrivateKeyB64 !== '') {
            $secret = base64_decode($activePrivateKeyB64, true);
            if ($secret !== false && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                $keys[$this->activeKeyId] = [
                    'public' => sodium_crypto_sign_publickey_from_secretkey($secret),
                    'secret' => $secret,
                ];
            }
        }

        // Typed loosely on purpose: this comes from json_decode() of an env
        // var, so a malformed value isn't guaranteed to be a string even
        // though the config array shape above declares it as one.
        /** @var array<string, mixed> $retired */
        $retired = $config['retired_public_keys'] ?? [];
        foreach ($retired as $keyId => $publicKeyB64) {
            if (isset($keys[$keyId]) || ! is_string($publicKeyB64)) {
                continue;
            }

            $public = base64_decode($publicKeyB64, true);
            if ($public !== false && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[$keyId] = ['public' => $public, 'secret' => null];
            }
        }

        $this->keys = $keys;
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    /**
     * Every currently-known public key, base64-encoded, for publishing
     * to scanner devices via the manifest — includes retired keys so
     * tickets signed before a rotation keep verifying offline.
     *
     * @return array<string, string>
     */
    public function publicKeys(): array
    {
        return array_map(
            static fn (array $key): string => base64_encode($key['public']),
            $this->keys
        );
    }

    /**
     * @return array{payload: string, payload_hash: string, signature: string, signing_key_id: string}
     */
    public function sign(string $ticketUlid, int $admitsTotal, int $expiresAtUnix): array
    {
        $key = $this->keys[$this->activeKeyId] ?? null;

        if ($key === null || $key['secret'] === null) {
            throw new RuntimeException("QrSigner has no private key configured for the active signing key '{$this->activeKeyId}'.");
        }

        $signedPortion = QrPayload::signedPortionFor($ticketUlid, $admitsTotal, $expiresAtUnix, $this->activeKeyId);
        $signature = sodium_crypto_sign_detached($signedPortion, $key['secret']);
        $signatureB64Url = $this->base64UrlEncode($signature);
        $payload = "{$signedPortion}.{$signatureB64Url}";

        return [
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'signature' => $signatureB64Url,
            'signing_key_id' => $this->activeKeyId,
        ];
    }

    public function verify(QrPayload $payload): bool
    {
        $key = $this->keys[$payload->keyId] ?? null;

        if ($key === null) {
            return false;
        }

        $signature = $this->base64UrlDecode($payload->signature);

        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $payload->signedPortion, $key['public']);
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): ?string
    {
        $padded = str_pad($encoded, (int) (4 * ceil(strlen($encoded) / 4)), '=');
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
