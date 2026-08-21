<?php

namespace App\Domain\Ticketing\Actions;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Exceptions\KeyRotationException;
use App\Domain\Ticketing\Models\QrSigningKey;
use Illuminate\Support\Facades\DB;

/**
 * Calls off a rotation that was published but never activated.
 *
 * A retired key keeps verifying — it is removed from *signing*, never from
 * the manifest — so tickets already in circulation under it are unaffected.
 * That is also why the active key cannot be retired directly: doing so would
 * leave nothing to sign new tickets with.
 */
class RetireQrSigningKey
{
    public function execute(QrSigningKey $key, User $retiredBy, ?string $ip = null, ?string $requestId = null): QrSigningKey
    {
        if ($key->status === QrSigningKey::ACTIVE) {
            throw KeyRotationException::cannotRetireActiveKey();
        }

        return DB::transaction(function () use ($key, $retiredBy, $ip, $requestId): QrSigningKey {
            $key->transitionTo(QrSigningKey::RETIRED, [
                'retired_at' => now(),
                'retired_by_user_id' => $retiredBy->id,
            ]);

            ActivityLog::create([
                'log_name' => 'qr_signing',
                'event' => 'key_retired',
                'description' => "Retired QR signing key '{$key->key_id}' without activating it",
                'causer_type' => $retiredBy->getMorphClass(),
                'causer_id' => $retiredBy->id,
                'subject_type' => $key->getMorphClass(),
                'subject_id' => $key->id,
                'properties' => ['key_id' => $key->key_id],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            return $key;
        });
    }
}
