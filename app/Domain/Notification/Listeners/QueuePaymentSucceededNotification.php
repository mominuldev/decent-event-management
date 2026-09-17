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
            // WhatsApp only. SMS was dropped on 2026-08-22 and email on
            // 2026-09-17, for the same reason each time: a buyer wants one
            // message per purchase, not a chain of them. A settled payment
            // queues ticket issuance, and issuance sends the
            // registration-confirmed email — the share card, and the amount
            // paid — seconds later, so an email from here arrived as a
            // duplicate receipt right before it. The amount the payer is
            // owed a record of now travels in that message
            // (`TicketNotificationPayload::amount_bdt`).
            //
            // The trade: if issuance fails, the payer hears nothing until
            // the job is replayed from `failed_jobs`. That was true of the
            // ticket before this too; the payment row is settled either way.
            channels: ['whatsapp'],
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
