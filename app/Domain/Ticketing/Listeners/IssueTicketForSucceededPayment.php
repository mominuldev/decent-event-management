<?php

namespace App\Domain\Ticketing\Listeners;

use App\Domain\Payment\Actions\VerifyManualPayment;
use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\Ticket;

/**
 * Issues a ticket once a payment succeeds via the gateway path
 * ({@see VerifyPayment}). {@see VerifyManualPayment} still calls
 * {@see IssueTicket} directly today rather than dispatching this event —
 * see D6 in docs/08 for the tracked inconsistency. Guarded against a
 * duplicate dispatch producing a second ticket for the same registration.
 */
class IssueTicketForSucceededPayment
{
    public function __construct(private readonly IssueTicket $issueTicket) {}

    public function handle(PaymentSucceeded $event): void
    {
        $registration = $event->payment->registration;

        if ($registration === null) {
            return;
        }

        if (Ticket::query()->where('registration_id', $registration->id)->exists()) {
            return;
        }

        $this->issueTicket->execute($registration);
    }
}
