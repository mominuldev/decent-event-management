<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Payment\Events\PaymentSucceeded;

class QueuePaymentSucceededNotification
{
    public function __construct(private readonly QueueNotification $queueNotification) {}

    public function handle(PaymentSucceeded $event): void
    {
        $payment = $event->payment;
        $attendee = $payment->attendee ?? $payment->registration?->attendee;

        if ($attendee === null) {
            return;
        }

        $this->queueNotification->execute(
            notifiable: $payment,
            templateKey: 'payment_succeeded',
            // Email and WhatsApp only. SMS was deliberately dropped
            // (2026-08-22): a ticket purchase used to fire three separate
            // messages — booking, payment, ticket — and a buyer wants one.
            // The ticket confirmation is the one that matters, since it is
            // the message that says they are in and where to be, so it keeps
            // the SMS channel and now carries the event details these two
            // used to. Removing it here is also two thirds of the SMS bill
            // for every ticket sold. Both keep their email, which costs
            // nothing and is where the receipt detail belongs.
            channels: ['email', 'whatsapp'],
            attendee: $attendee,
            payload: [
                'full_name' => $attendee->full_name,
                'full_name_bn' => $attendee->banglaName(),
                'payment_number' => $payment->payment_number,
                'amount_bdt' => number_format($payment->amount_paid_paisa / 100, 2),
                'method' => $payment->method,
                'gateway_transaction_id' => $payment->gateway_transaction_id ?? '',
            ],
        );
    }
}
