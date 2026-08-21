<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Events\SigningKeyRotated;
use App\Domain\Ticketing\Models\QrSigningKey;
use Illuminate\Database\Eloquent\Collection;

/**
 * docs/06 §6.5: a key rotation "notifies all Event Managers".
 *
 * Audience is resolved by role here rather than by permission, which is the
 * one place that reads against this codebase's own "check permissions,
 * never role names" rule — that rule governs *authorization*, and this is
 * not an authorization decision. docs/06 names the audience as a job title,
 * and no permission in config/rbac.php means "runs the gate on event day":
 * the Volunteer role holds the check-in permissions too, and paging every
 * volunteer's phone about key material would be both noise and a
 * small information leak.
 */
class NotifyEventManagersOfKeyRotation
{
    public function __construct(
        private readonly QueueNotification $queue,
    ) {}

    public function handle(SigningKeyRotated $event): void
    {
        $key = QrSigningKey::query()->where('key_id', $event->keyId)->first();

        if ($key === null) {
            return;
        }

        /** @var Collection<int, User> $managers */
        $managers = User::query()
            ->role('Event Manager')
            ->where('status', 'active')
            ->whereNotNull('email')
            ->get();

        foreach ($managers as $manager) {
            $this->queue->executeForRecipient(
                notifiable: $key,
                templateKey: 'qr_signing_key_rotated',
                channel: 'email',
                recipient: $manager->email,
                payload: [
                    'key_id' => $event->keyId,
                    'rotated_by' => $event->activatedByName,
                    'rotated_at' => now()->timezone(config('app.timezone'))->format('j M Y, g:i A'),
                    'device_warning' => $event->forced
                        ? "Warning: this rotation was forced with {$event->unsyncedDeviceCount} scanner device(s) not yet synced. Those devices cannot verify tickets issued from now on until they sync."
                        : 'All active scanner devices had confirmed the new key before it was activated.',
                ],
                // Distinct per rotation: the same key id must never be able
                // to swallow a second, later notification about itself.
                dedupeSuffix: (string) $key->activated_at?->getTimestamp(),
            );
        }
    }
}
