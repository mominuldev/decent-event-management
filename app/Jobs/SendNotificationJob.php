<?php

namespace App\Jobs;

use App\Domain\Notification\Channels\NotificationChannelResolver;
use App\Domain\Notification\Models\Notification;
use App\Domain\Shared\Models\EventSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Drains one outbox row (docs/01 §1.6, ADR-07). Retry schedule matches
 * `config/horizon.php`'s `supervisor-notifications` lane exactly: 5
 * attempts, exponential backoff 1m/5m/15m/1h/6h, then `failed` for
 * manual review via the admin resend flow.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int, int> */
    private const array BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];

    public int $tries = 5;

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationChannelResolver $resolver): void
    {
        $notification = Notification::find($this->notificationId);

        if ($notification === null) {
            return;
        }

        // Terminal already — a duplicate dispatch (e.g. a retried queue
        // connection redelivering the same job) is a no-op.
        if (! in_array($notification->status, ['queued', 'sending'], true)) {
            return;
        }

        if (! $this->channelEnabled($notification->channel)) {
            $notification->transitionTo('cancelled');
            $notification->save();

            return;
        }

        if ($notification->status === 'queued') {
            $notification->transitionTo('sending');
            $notification->save();
        }

        $result = $resolver->forChannel($notification->channel)->send($notification);

        if ($result->isSent()) {
            $notification->transitionTo('sent');
            $notification->provider = $result->provider;
            $notification->provider_message_id = $result->providerMessageId;
            $notification->segment_count = $result->segmentCount !== null ? max(0, $result->segmentCount) : null;
            $notification->cost_paisa = $result->costPaisa !== null ? max(0, $result->costPaisa) : null;
            $notification->sent_at = now();
            $notification->save();

            return;
        }

        $notification->attempts++;
        $notification->last_error = $result->errorMessage;

        if ($notification->attempts >= $notification->max_attempts) {
            $notification->transitionTo('failed');
            $notification->failed_at = now();
            $notification->save();

            return;
        }

        $notification->transitionTo('queued');
        $notification->save();

        $this->release(self::BACKOFF_SECONDS[$notification->attempts - 1] ?? 21600);
    }

    private function channelEnabled(string $channel): bool
    {
        $setting = EventSetting::query()->where('key', "notification.{$channel}_enabled")->first();

        // No kill-switch row for this channel means nothing is gating it.
        return $setting === null || $setting->typedValue() === true;
    }
}
