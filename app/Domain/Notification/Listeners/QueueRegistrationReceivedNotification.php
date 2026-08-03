<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Registration\Events\RegistrationCreated;

class QueueRegistrationReceivedNotification
{
    public function __construct(private readonly QueueNotification $queueNotification) {}

    public function handle(RegistrationCreated $event): void
    {
        $registration = $event->registration;
        $attendee = $registration->attendee;

        if ($attendee === null) {
            return;
        }

        $this->queueNotification->execute(
            notifiable: $registration,
            templateKey: 'registration_received',
            channels: ['email', 'sms', 'whatsapp'],
            attendee: $attendee,
            payload: [
                'full_name' => $attendee->full_name,
                'registration_number' => $registration->registration_number,
                'registration_ulid' => $registration->ulid,
            ],
        );
    }
}
