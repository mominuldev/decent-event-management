<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Shared\Models\EventSetting;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * The `{{variables}}` a ticket's messages interpolate — the one
 * definition, shared by the `registration_confirmed` message the
 * `TicketIssued` listener sends at issuance and the `ticket_delivered`
 * message `ResendTicketNotification` sends when an operator sends the
 * ticket.
 *
 * Shared rather than rebuilt, for the reason docs/08 R12 keeps naming: a
 * second assembly of this payload is a second implementation of the
 * message, and the first thing it would drift on is the SMS — where a
 * variable this class supplies and the other does not is not an error but
 * a literal `{{event_name}}` delivered to a real ticket-holder, because
 * `QueueNotification::interpolate()` leaves an unrecognised placeholder
 * verbatim rather than throwing. One payload also means an editor can
 * move a placeholder between the two templates freely.
 *
 * Every value is read live, not from a notification row written earlier:
 * a ticket is sent some time after the registration was confirmed, and a
 * venue corrected in the settings screen in between is exactly the kind
 * of thing that should reach it.
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

            // The reference the registration-confirmed message quotes in
            // place of the ticket number, which that message must not
            // carry. It is also what the share card prints.
            'registration_number' => (string) $ticket->registration?->registration_number,

            // What the registration cost, so the registration-confirmed
            // email is also the receipt — the payment-received email was
            // folded into it (2026-09-17). Read from the registration's own
            // total rather than the Payment module's rows, which Ticketing
            // does not reach into; the two agree by construction, since a
            // payment settles only against `amount_due_paisa`.
            'amount_bdt' => $this->amountBdt($ticket),

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

    private function amountBdt(Ticket $ticket): string
    {
        $paisa = $ticket->registration?->total_paisa;

        return $paisa === null ? '' : number_format(((int) $paisa) / 100, 2);
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
