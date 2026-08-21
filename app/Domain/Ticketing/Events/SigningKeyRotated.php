<?php

namespace App\Domain\Ticketing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ticketing announces that the signing key changed and knows nothing about
 * who cares — the Event Manager notification docs/06 §6.5 requires is a
 * listener's job, following the module-boundary rule (D6).
 */
class SigningKeyRotated
{
    use Dispatchable;

    public function __construct(
        public readonly string $keyId,
        public readonly int $activatedByUserId,
        public readonly string $activatedByName,
        public readonly bool $forced,
        public readonly int $unsyncedDeviceCount,
    ) {}
}
