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
            // Email and WhatsApp only, for the same reason as
            // `QueueRegistrationReceivedNotification`: this is a payment
            // confirmation, and a buyer paying by bank transfer would
            // otherwise still receive two SMS for one purchase — this and
            // the ticket. The ticket confirmation is the one that keeps SMS.
            //
            // `payment_failed` and `refund_issued` deliberately keep theirs:
            // neither is part of a normal purchase, both need attention
            // rather than a record, and an email nobody opens is no use for
            // a payment that did not go through.
            channels: ['email', 'whatsapp'],
            attendee: $attendee,
            payload: [
                'full_name' => $attendee->full_name,
                'full_name_bn' => $attendee->banglaName(),
                'payment_number' => $payment->payment_number,
                'amount_bdt' => number_format($payment->amount_paid_paisa / 100, 2),
            ],
        );
    }
}
