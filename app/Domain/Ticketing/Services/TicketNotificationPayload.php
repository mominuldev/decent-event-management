<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Shared\Models\EventSetting;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * The `{{variables}}` a `ticket_delivered` message interpolates — the one
 * definition, shared by the `TicketIssued` listener that sends it the
 * first time and by `ResendTicketNotification` when an operator sends it
 * again.
 *
 * Shared rather than rebuilt, for the reason docs/08 R12 keeps naming: a
 * resend that assembles its own payload is a second implementation of the
 * message, and the first thing it would drift on is the SMS — where a
 * variable this class supplies and the other does not is not an error but
 * a literal `{{event_name}}` delivered to a real ticket-holder, because
 * `QueueNotification::interpolate()` leaves an unrecognised placeholder
 * verbatim rather than throwing.
 *
 * Every value is read live, not from the notification row that was
 * written at issuance: a resend exists because something about the first
 * attempt was wrong, and a venue corrected in the settings screen after
 * the original send is exactly the kind of thing it is for.
 */
class TicketNotificationPayload
{
    /**
     * @return array<string, string>
     */
    public function for(Ticket $ticket): array
    {
        $attendee = $ticket->attendee;

        return [
            // The original four. Kept exactly as they were: the email and
            // WhatsApp templates interpolate them.
            'full_name' => (string) $attendee?->full_name,
            'full_name_bn' => (string) $attendee?->banglaName(),
            'ticket_number' => (string) $ticket->ticket_number,
            'admits_total' => (string) $ticket->admits_total,

            // Added for the SMS, which is the only message a purchase
            // sends and so has to say what the other two used to.
            'customer_name' => (string) $attendee?->full_name,
            'ticket_id' => (string) $ticket->ticket_number,
            'event_name' => $this->setting('event.name_en', 'event.name'),
            'venue' => $this->sessionVenue($ticket) ?? $this->setting('event.venue_en', 'event.venue'),
            'event_date' => $this->eventStart($ticket)?->format('j M Y') ?? '',
            'event_time' => $this->eventStart($ticket)?->format('g:i A') ?? '',
        ];
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
    private function eventStart(Ticket $ticket): ?Carbon
    {
        $sessionStart = $ticket->eventSession?->starts_at;

        if ($sessionStart instanceof Carbon) {
            return $sessionStart;
        }

        $eventDate = EventSetting::query()->where('key', 'event.date')->value('value');

        return is_string($eventDate) && $eventDate !== '' ? Carbon::parse($eventDate) : null;
    }

    private function sessionVenue(Ticket $ticket): ?string
    {
        $venue = $ticket->eventSession?->venue;

        return is_string($venue) && trim($venue) !== '' ? trim($venue) : null;
    }
}
