<?php

namespace App\Domain\Ticketing\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A rotation step that was refused because it would break the gate —
 * rendered as a 422 in the uniform {code, message, request_id} envelope
 * rather than a 500, because every one of these is an operator asking for
 * something the ordering in docs/06 §6.5 does not allow yet.
 */
class KeyRotationException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'key_rotation_refused',
    ) {
        parent::__construct($message);
    }

    public static function noPrivateKeyAvailable(string $keyId): self
    {
        return new self(
            "No private key for '{$keyId}' is available to this server. Add it to QR_SIGNING_PRIVATE_KEYS (or your secret manager) and redeploy before activating it.",
            'signing_key_unavailable',
        );
    }

    public static function devicesNotSynced(int $outstanding, int $total): self
    {
        return new self(
            "{$outstanding} of {$total} active scanner devices have not synced since this key was published. Activating now would make every ticket signed with it unverifiable at those gates.",
            'devices_not_synced',
        );
    }

    public static function alreadyRegistered(string $keyId): self
    {
        return new self("Signing key '{$keyId}' is already registered.", 'signing_key_already_registered');
    }

    public static function cannotRetireActiveKey(): self
    {
        return new self(
            'The active signing key cannot be retired — activate its replacement first, which retires this one as part of the same step.',
            'cannot_retire_active_key',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'request_id' => $request->header('X-Request-Id'),
        ], 422);
    }
}
