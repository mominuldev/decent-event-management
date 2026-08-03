<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Ticketing\Events\TicketIssued;

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
                'full_name' => $attendee->full_name,
                'ticket_number' => $ticket->ticket_number,
                'admits_total' => (string) $ticket->admits_total,
            ],
        );
    }
}
