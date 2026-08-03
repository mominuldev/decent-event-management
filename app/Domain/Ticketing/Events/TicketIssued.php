<?php

namespace App\Domain\Ticketing\Events;

use App\Domain\Payment\Actions\VerifyManualPayment;
use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Listeners\IssueTicketForSucceededPayment;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once a ticket is issued and activated, from the single choke
 * point ({@see IssueTicket}) both the
 * gateway path ({@see VerifyPayment} via
 * {@see IssueTicketForSucceededPayment})
 * and the manual-verification path
 * ({@see VerifyManualPayment}) go through —
 * so a "ticket delivered" notification listener covers both without
 * needing the D6 module-boundary cleanup (docs/08) first.
 */
class TicketIssued
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
