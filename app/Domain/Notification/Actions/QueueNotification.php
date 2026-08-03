<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Registration\Models\Attendee;
use App\Jobs\SendNotificationJob;
use Illuminate\Database\Eloquent\Model;

/**
 * The single entry point every notification-triggering listener calls
 * (docs/01 §1.6). Writes one outbox row per requested channel that has
 * both an active template and a resolvable recipient, inside the
 * caller's transaction, then dispatches the drain job for each —
 * deferred to after commit so the worker never races a row that isn't
 * visible yet.
 */
class QueueNotification
{
    /**
     * @param  array<int, string>  $channels  e.g. ['email', 'sms', 'whatsapp']
     * @param  array<string, mixed>  $payload  interpolated into the template as `{{key}}`
     */
    public function execute(
        Model $notifiable,
        string $templateKey,
        array $channels,
        Attendee $attendee,
        array $payload = [],
        string $locale = 'en',
    ): void {
        foreach ($channels as $channel) {
            $recipient = $this->recipientFor($channel, $attendee);

            if ($recipient === null) {
                continue;
            }

            $template = NotificationTemplate::query()
                ->where('key', $templateKey)
                ->where('channel', $channel)
                ->where('locale', $locale)
                ->where('is_active', true)
                ->first();

            if ($template === null) {
                continue;
            }

            $dedupeKey = implode(':', [$notifiable->getMorphClass(), $notifiable->getKey(), $templateKey, $channel]);

            if (Notification::query()->where('dedupe_key', $dedupeKey)->exists()) {
                continue;
            }

            $notification = Notification::create([
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'attendee_id' => $attendee->id,
                'template_key' => $templateKey,
                'channel' => $channel,
                'locale' => $locale,
                'recipient' => $recipient,
                'subject' => $this->interpolate($template->subject, $payload),
                'body_rendered' => $this->interpolate($template->body, $payload),
                'payload' => $payload,
                'status' => 'queued',
                'max_attempts' => 5,
                'dedupe_key' => $dedupeKey,
            ]);

            SendNotificationJob::dispatch($notification->id)->afterCommit();
        }
    }

    private function recipientFor(string $channel, Attendee $attendee): ?string
    {
        return match ($channel) {
            'email' => $attendee->email,
            'sms' => $attendee->mobile,
            'whatsapp' => $attendee->whatsapp_number ?? $attendee->mobile,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function interpolate(?string $text, array $payload): ?string
    {
        if ($text === null) {
            return null;
        }

        $search = [];
        $replace = [];

        foreach ($payload as $key => $value) {
            $search[] = '{{'.$key.'}}';
            $replace[] = (string) $value;
        }

        return str_replace($search, $replace, $text);
    }
}
