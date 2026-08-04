<?php

namespace App\Domain\Ticketing\Support;

use App\Domain\Ticketing\Services\QrSigner;
use Illuminate\Support\Carbon;

/**
 * Parses and encodes the QR payload format (docs/06 §6.5):
 * `DTM1.<ticket_ulid>.<admits_total>.<exp_unix>.<key_id>.<signature_b64url>`.
 *
 * `signedPortion` is everything before the signature — the exact bytes
 * {@see QrSigner} signs and verifies.
 */
final class QrPayload
{
    private function __construct(
        public readonly string $ticketUlid,
        public readonly int $admitsTotal,
        public readonly int $expiresAtUnix,
        public readonly string $keyId,
        public readonly string $signature,
        public readonly string $signedPortion,
        public readonly string $raw,
    ) {}

    public static function parse(string $raw): ?self
    {
        $parts = explode('.', $raw);

        if (count($parts) !== 6 || $parts[0] !== 'DTM1') {
            return null;
        }

        [, $ulid, $admitsTotal, $expUnix, $keyId, $signature] = $parts;

        if (strlen($ulid) !== 26 || $keyId === '' || $signature === '') {
            return null;
        }

        if (! ctype_digit($admitsTotal) || ! ctype_digit($expUnix)) {
            return null;
        }

        $signedPortion = implode('.', ['DTM1', $ulid, $admitsTotal, $expUnix, $keyId]);

        return new self(
            ticketUlid: $ulid,
            admitsTotal: (int) $admitsTotal,
            expiresAtUnix: (int) $expUnix,
            keyId: $keyId,
            signature: $signature,
            signedPortion: $signedPortion,
            raw: $raw,
        );
    }

    public static function signedPortionFor(string $ticketUlid, int $admitsTotal, int $expiresAtUnix, string $keyId): string
    {
        return implode('.', ['DTM1', $ticketUlid, (string) $admitsTotal, (string) $expiresAtUnix, $keyId]);
    }

    public function isExpired(?Carbon $now = null): bool
    {
        return $this->expiresAtUnix < ($now ?? now())->timestamp;
    }
}
