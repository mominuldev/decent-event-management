<?php

namespace App\Domain\Ticketing\Listeners;

use App\Domain\Payment\Actions\VerifyManualPayment;
use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Jobs\IssueTicketForRegistrationJob;

/**
 * Issues a ticket once a payment succeeds via the gateway path
 * ({@see VerifyPayment}). {@see VerifyManualPayment} still calls
 * {@see IssueTicket} directly today rather than dispatching this event —
 * see D6 in docs/08 for the tracked inconsistency.
 *
 * Deliberately a thin dispatcher, matching {@see GenerateTicketAssets}.
 * `PaymentSucceeded` is dispatched from inside `VerifyPayment`'s own
 * transaction, so issuing here would put ticket issuance inside the
 * transaction that settles the money — and a throw would roll the
 * settlement back, leaving the payer charged at the gateway with the
 * registration still `pending_payment`. `->afterCommit()` keeps the job
 * out of that transaction; {@see IssueTicketForRegistrationJob} carries
 * the full reasoning and the duplicate guard.
 */
class IssueTicketForSucceededPayment
{
    public function handle(PaymentSucceeded $event): void
    {
        $registration = $event->payment->registration;

        if ($registration === null) {
            return;
        }

        IssueTicketForRegistrationJob::dispatch($registration->id)->afterCommit();
    }
}
