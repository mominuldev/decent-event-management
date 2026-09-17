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
            // WhatsApp only, matching `QueuePaymentSucceededNotification`:
            // an approved manual payment queues ticket issuance, and
            // issuance sends the registration-confirmed email with the
            // amount paid, so an email from here was a duplicate receipt
            // arriving seconds before it. SMS went on 2026-08-22 for the
            // same one-message-per-purchase reason.
            //
            // `payment_failed` and `refund_issued` deliberately keep theirs:
            // neither is part of a normal purchase, both need attention
            // rather than a record, and an email nobody opens is no use for
            // a payment that did not go through.
            channels: ['whatsapp'],
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
