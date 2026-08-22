<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Payment\Events\RefundIssued;

class QueueRefundIssuedNotification
{
    public function __construct(private readonly QueueNotification $queueNotification) {}

    public function handle(RefundIssued $event): void
    {
        $refund = $event->refund;
        $payment = $refund->payment;

        if ($payment === null) {
            return;
        }

        $attendee = $payment->attendee ?? $payment->registration?->attendee;

        if ($attendee === null) {
            return;
        }

        // No WhatsApp per the channel matrix (docs/01 §1.6).
        $this->queueNotification->execute(
            notifiable: $refund,
            templateKey: 'refund_issued',
            channels: ['email', 'sms'],
            attendee: $attendee,
            payload: [
                'full_name' => $attendee->full_name,
                'full_name_bn' => $attendee->banglaName(),
                'refund_number' => $refund->refund_number,
                'amount_bdt' => number_format($refund->amount_paisa / 100, 2),
            ],
        );
    }
}
