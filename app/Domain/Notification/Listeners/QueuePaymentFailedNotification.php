<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Payment\Events\PaymentFailed;

class QueuePaymentFailedNotification
{
    public function __construct(private readonly QueueNotification $queueNotification) {}

    public function handle(PaymentFailed $event): void
    {
        $payment = $event->payment;
        $attendee = $payment->attendee ?? $payment->registration?->attendee;

        if ($attendee === null) {
            return;
        }

        // No WhatsApp per the channel matrix (docs/01 §1.6) — a failed
        // payment gets a retry link over email/SMS only.
        $this->queueNotification->execute(
            notifiable: $payment,
            templateKey: 'payment_failed',
            channels: ['email', 'sms'],
            attendee: $attendee,
            payload: [
                'full_name' => $attendee->full_name,
                'full_name_bn' => $attendee->banglaName(),
                'payment_number' => $payment->payment_number,
                'amount_bdt' => number_format($payment->amount_due_paisa / 100, 2),
                'registration_ulid' => $payment->registration?->ulid,
            ],
        );
    }
}
