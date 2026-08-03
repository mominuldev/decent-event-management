<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Models\Notification;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Re-queues a `failed`/`bounced` notification as a fresh outbox row
 * (docs/06 §6.7's audit-trail rule for privileged actions). A clone
 * rather than a reset-in-place, because the original's `dedupe_key` is
 * already claimed by the unique index and the at-least-once guarantee
 * must not let a retried resend double-send.
 */
class ResendNotification
{
    public function execute(Notification $notification, User $resentBy, ?string $ip = null, ?string $requestId = null): Notification
    {
        if (! in_array($notification->status, ['failed', 'bounced'], true)) {
            throw new InvalidArgumentException("Notification cannot be resent from status: {$notification->status}");
        }

        return DB::transaction(function () use ($notification, $resentBy, $ip, $requestId): Notification {
            $fresh = Notification::create([
                'notifiable_type' => $notification->notifiable_type,
                'notifiable_id' => $notification->notifiable_id,
                'attendee_id' => $notification->attendee_id,
                'template_key' => $notification->template_key,
                'channel' => $notification->channel,
                'locale' => $notification->locale,
                'recipient' => $notification->recipient,
                'subject' => $notification->subject,
                'body_rendered' => $notification->body_rendered,
                'payload' => $notification->payload,
                'status' => 'queued',
                'max_attempts' => $notification->max_attempts,
                'dedupe_key' => $notification->dedupe_key.':resend-'.now()->timestamp,
            ]);

            SendNotificationJob::dispatch($fresh->id)->afterCommit();

            ActivityLog::create([
                'log_name' => 'notification',
                'event' => 'resent',
                'description' => "Resent notification {$notification->ulid} as {$fresh->ulid}",
                'causer_type' => $resentBy->getMorphClass(),
                'causer_id' => $resentBy->id,
                'subject_type' => $notification->getMorphClass(),
                'subject_id' => $notification->id,
                'properties' => [
                    'original_ulid' => $notification->ulid,
                    'resent_ulid' => $fresh->ulid,
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            return $fresh;
        });
    }
}
