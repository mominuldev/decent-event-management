<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Payment\Events\ManualPaymentVerified;

class QueueManualPaymentVerifiedNotification
{
    public function __construct(private readonly QueueNotification $queueNotification) {}

    public function handle(ManualPaymentVerified $event): void
    {
        $payment = $event->payment;
        $attendee = $payment->attendee ?? $payment->registration?->attendee;

        if ($attendee === null) {
            return;
        }

        $this->queueNotification->execute(
            notifiable: $payment,
            templateKey: 'payment_manual_verified',
            channels: ['email', 'sms', 'whatsapp'],
            attendee: $attendee,
            payload: [
                'full_name' => $attendee->full_name,
                'payment_number' => $payment->payment_number,
                'amount_bdt' => number_format($payment->amount_paid_paisa / 100, 2),
            ],
        );
    }
}
