<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Ticketing\Events\TicketIssued;
use Illuminate\Support\Carbon;

/**
 * The ticket confirmation — and, since 2026-08-22, **the only SMS a
 * ticket purchase sends.** Booking and payment confirmations were dropped
 * from the `sms` channel (they keep email) so a buyer receives one
 * message rather than three, and pays for one rather than three.
 *
 * Because it is the only one, it carries the details the other two used
 * to: what the event is, when it is, and where. Those come from
 * `event_settings` and the ticket's own session, and the English pair
 * (`event.name_en`/`event.venue_en`) exists because SMS is English —
 * a single Bangla character would drop the whole message from 160
 * characters per segment to 70.
 */
class QueueTicketDeliveredNotification
{
    public function __construct(private readonly QueueNotification $queueNotification) {}

    public function handle(TicketIssued $event): void
    {
        $ticket = $event->ticket;
        $attendee = $ticket->attendee;

        if ($attendee === null) {
            return;
        }

        $this->queueNotification->execute(
            notifiable: $ticket,
            templateKey: 'ticket_delivered',
            channels: ['email', 'sms', 'whatsapp'],
            attendee: $attendee,
            payload: [
                // The original four. Kept exactly as they were: the email
                // and WhatsApp templates interpolate them, and an
                // unrecognised `{{key}}` is left in the rendered body
                // verbatim rather than erroring — a renamed variable would
                // ship "Hi {{full_name}}" to a real person.
                'full_name' => $attendee->full_name,
                'full_name_bn' => $attendee->banglaName(),
                'ticket_number' => $ticket->ticket_number,
                'admits_total' => (string) $ticket->admits_total,

                // Added for the SMS, which is now the only one a purchase
                // sends and so has to say what the other two used to.
                'customer_name' => $attendee->full_name,
                'ticket_id' => $ticket->ticket_number,
                'event_name' => $this->setting('event.name_en', 'event.name'),
                'venue' => $this->sessionVenue($event) ?? $this->setting('event.venue_en', 'event.venue'),
                'event_date' => $this->eventStart($event)?->format('j M Y') ?? '',
                'event_time' => $this->eventStart($event)?->format('g:i A') ?? '',
            ],
        );
    }

    /**
     * The English value if one is set, falling back to the original — so a
     * deployment that has not run the seeder still sends a name rather
     * than an empty gap in the middle of a sentence.
     */
    private function setting(string $key, string $fallbackKey): string
    {
        foreach ([$key, $fallbackKey] as $candidate) {
            $value = EventSetting::query()->where('key', $candidate)->value('value');

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * The ticket's own session start, which is more specific than the
     * event-wide date — a ticket for the evening programme should not say
     * the morning's time.
     */
    private function eventStart(TicketIssued $event): ?Carbon
    {
        $sessionStart = $event->ticket->eventSession?->starts_at;

        if ($sessionStart instanceof Carbon) {
            return $sessionStart;
        }

        $eventDate = EventSetting::query()->where('key', 'event.date')->value('value');

        return is_string($eventDate) && $eventDate !== '' ? Carbon::parse($eventDate) : null;
    }

    private function sessionVenue(TicketIssued $event): ?string
    {
        $venue = $event->ticket->eventSession?->venue;

        return is_string($venue) && trim($venue) !== '' ? trim($venue) : null;
    }
}
