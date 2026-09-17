<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Ticketing\Events\TicketIssued;
use App\Domain\Ticketing\Services\TicketNotificationPayload;

/**
 * The message a ticket-holder gets the moment their ticket is issued —
 * and, since 2026-09-17, it is **not the ticket**.
 *
 * It says the registration succeeded and carries the "আমি থাকছি!" share
 * card, and nothing that admits anyone: no QR, no ticket number. The
 * ticket itself — QR, number, gate details — is a separate
 * `ticket_delivered` message that staff send from the admin console
 * (`POST /admin/tickets/{ticket}/resend`, `resend-all`), on the
 * channels they choose, when they choose to. Splitting them lets the
 * organisers hold every QR back until the event is close, while the
 * buyer still hears straight away that their money bought a seat.
 *
 * Email only. The old automatic ticket message was the one SMS a
 * purchase sent, and it earned that segment by carrying the QR-ticket
 * notice and the gate details; a "you are registered" line does not, and
 * the SMS now goes out with the ticket instead. WhatsApp is left off for
 * the reason `ResendTicketNotification` gives: it still resolves to a
 * fake driver, and a fake `sent` row for a message that never left the
 * building is worse than no row.
 *
 * The payload is `TicketNotificationPayload`, shared with the ticket send,
 * so both messages interpolate one set of variables and an editor can
 * move a placeholder between the two templates without one of them
 * delivering it as literal text.
 */
class QueueRegistrationConfirmedNotification
{
    /** @var array<int, string> */
    public const array CHANNELS = ['email'];

    public const string TEMPLATE_KEY = 'registration_confirmed';

    public function __construct(
        private readonly QueueNotification $queueNotification,
        private readonly TicketNotificationPayload $payload,
    ) {}

    public function handle(TicketIssued $event): void
    {
        $ticket = $event->ticket;
        $attendee = $ticket->attendee;

        if ($attendee === null) {
            return;
        }

        $this->queueNotification->execute(
            notifiable: $ticket,
            templateKey: self::TEMPLATE_KEY,
            channels: self::CHANNELS,
            attendee: $attendee,
            payload: $this->payload->for($ticket),
        );
    }
}
