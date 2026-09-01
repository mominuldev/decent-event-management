<?php

namespace App\Jobs;

use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Issues the ticket for a registration whose payment has settled, on the
 * `tickets` Horizon lane.
 *
 * This is a job rather than work done inline for one reason, and it is a
 * money one. {@see VerifyPayment::handle()}
 * dispatches `PaymentSucceeded` from *inside* its own DB transaction, so
 * anything that runs synchronously off that event runs inside it too — and
 * a throw there rolls the settlement back. Ticket issuance can throw for
 * reasons that have nothing to do with the money: the commonest is a
 * deployment where `qr-signing:generate-key` was never run, which makes
 * `QrSigner::sign()` throw on every issuance. The payer is then charged at
 * the gateway while the payment reverts to `initiated` and the
 * registration to `pending_payment`, so the return page polls a
 * "confirming your payment" spinner for ever and every retry repeats the
 * rollback.
 *
 * Off the transaction, settlement is durable the moment the gateway
 * confirms it, and a failure to issue is a retryable job that ends up in
 * `failed_jobs` where it can be seen and replayed — never a silently
 * unpaid registration.
 *
 * Idempotent by construction: a registration that already has a ticket is
 * a no-op, so a retry or a double dispatch never mints a second one.
 */
class IssueTicketForRegistrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $registrationId)
    {
        $this->onQueue('tickets');
    }

    public function handle(IssueTicket $issueTicket): void
    {
        $registration = Registration::find($this->registrationId);

        if ($registration === null) {
            return;
        }

        if (Ticket::query()->where('registration_id', $registration->id)->exists()) {
            return;
        }

        $issueTicket->execute($registration);
    }
}
