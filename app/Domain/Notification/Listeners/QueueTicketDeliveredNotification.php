<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Ticketing\Events\TicketIssued;
use App\Domain\Ticketing\Services\TicketNotificationPayload;

/**
 * The ticket confirmation — and, since 2026-08-22, **the only SMS a
 * ticket purchase sends.** Booking and payment confirmations were dropped
 * from the `sms` channel (they keep email) so a buyer receives one
 * message rather than three, and pays for one rather than three.
 *
 * Because it is the only one, it carries the details the other two used
 * to: what the event is, when it is, and where. Those are assembled by
 * `TicketNotificationPayload`, which is shared with
 * `ResendTicketNotification` so an operator resending a ticket sends the
 * same message this listener does rather than a second version of it.
 */
class QueueTicketDeliveredNotification
{
    /** The channels a ticket confirmation goes out on — see ResendTicketNotification. */
    public const array CHANNELS = ['email', 'sms', 'whatsapp'];

    public const string TEMPLATE_KEY = 'ticket_delivered';

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
