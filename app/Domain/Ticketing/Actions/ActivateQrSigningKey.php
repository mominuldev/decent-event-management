<?php

namespace App\Domain\Ticketing\Actions;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Contracts\ScannerFleetStatus;
use App\Domain\Ticketing\Events\SigningKeyRotated;
use App\Domain\Ticketing\Exceptions\KeyRotationException;
use App\Domain\Ticketing\Models\QrSigningKey;
use App\Domain\Ticketing\Services\QrSigningKeyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Steps 2–3 of docs/06 §6.5's rotation: confirm device sync, then — and
 * only then — start signing with the new key.
 *
 * This is the step the whole feature exists for. Getting the ordering wrong
 * by hand ("start signing with the new key before devices have it") does not
 * fail loudly on the server; it fails at a gate, on event day, as every
 * newly-issued ticket is rejected by a device that cannot verify it. So the
 * ordering is a precondition here, not a checklist item:
 *
 *   - every active scanner device must have completed a manifest sync since
 *     the key was published, and
 *   - this server must actually hold the private half.
 *
 * `$force` exists because the alternative is worse: an operator facing one
 * permanently-offline device would otherwise be locked out of rotating at
 * all, and would go back to editing .env by hand with no audit trail. It is
 * recorded distinctly in the activity log.
 */
class ActivateQrSigningKey
{
    public function __construct(
        private readonly QrSigningKeyRegistry $registry,
        private readonly ScannerFleetStatus $fleet,
    ) {}

    public function execute(
        QrSigningKey $key,
        User $activatedBy,
        bool $force = false,
        ?string $ip = null,
        ?string $requestId = null,
    ): QrSigningKey {
        if ($this->registry->privateKeyFor($key->key_id) === null) {
            throw KeyRotationException::noPrivateKeyAvailable($key->key_id);
        }

        $sync = $this->fleet->syncStatusSince($key->publishedAt());
        $outstanding = count($sync['outstanding']);

        if ($outstanding > 0 && ! $force) {
            throw KeyRotationException::devicesNotSynced($outstanding, $sync['total']);
        }

        $rotated = DB::transaction(function () use ($key, $activatedBy, $force, $sync, $outstanding, $ip, $requestId): QrSigningKey {
            $previous = QrSigningKey::query()->active()->lockForUpdate()->first();

            // Retired before the new one is activated, not after: the unique
            // index on the generated active_singleton column permits exactly
            // one active row, so the other order deadlocks against itself.
            $previous?->transitionTo(QrSigningKey::RETIRED, [
                'retired_at' => now(),
                'retired_by_user_id' => $activatedBy->id,
            ]);

            $key->transitionTo(QrSigningKey::ACTIVE, [
                'activated_at' => now(),
                'activated_by_user_id' => $activatedBy->id,
            ]);

            ActivityLog::create([
                'log_name' => 'qr_signing',
                'event' => $force ? 'key_activated_forced' : 'key_activated',
                'description' => "Activated QR signing key '{$key->key_id}'"
                    .($previous !== null ? ", retiring '{$previous->key_id}'" : '')
                    .($force ? " with {$outstanding} device(s) unsynced" : ''),
                'causer_type' => $activatedBy->getMorphClass(),
                'causer_id' => $activatedBy->id,
                'subject_type' => $key->getMorphClass(),
                'subject_id' => $key->id,
                'properties' => [
                    'key_id' => $key->key_id,
                    'previous_key_id' => $previous?->key_id,
                    'forced' => $force,
                    'devices_total' => $sync['total'],
                    'devices_synced' => $sync['synced'],
                    'devices_outstanding' => $sync['outstanding'],
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            return $key;
        });

        // docs/06 §6.5: rotation "notifies all Event Managers". Dispatched
        // after the transaction so nothing is announced that then rolls back.
        SigningKeyRotated::dispatch($rotated->key_id, $activatedBy->id, $activatedBy->name, $force, $outstanding);

        return $rotated;
    }
}
