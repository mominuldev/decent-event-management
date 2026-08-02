<?php

namespace App\Domain\CheckIn\Services;

use App\Domain\CheckIn\Models\Gate;
use App\Domain\Ticketing\Models\Ticket;

/**
 * The manifest/scope/admission stages of the four-stage scan decision
 * (docs/01 §1.5, docs/04 §4.7 "Check-in scan result"). Format and
 * signature verification (stages 1-2) happen upstream via QrSigner
 * (Phase 6) before a resolved {@see Ticket} ever reaches this policy.
 *
 * Returns one of the `check_ins.result` values. `admitted` is advisory
 * only — the caller must still perform the write through
 * {@see Ticket::tryAdmit()}, which is the actual concurrency-safe gate.
 */
class AdmissionPolicy
{
    public function evaluate(Ticket $ticket, Gate $gate, int $partySize): string
    {
        if (in_array($ticket->status, ['voided', 'refunded'], true)) {
            return 'revoked';
        }

        if ($ticket->status === 'issued') {
            return 'unpaid';
        }

        if ($ticket->qrCode?->expires_at?->isPast()) {
            return 'expired';
        }

        if (! $gate->allowsTicketType($ticket->ticket_type_id)) {
            return 'wrong_gate';
        }

        if ($gate->event_session_id !== null
            && $ticket->event_session_id !== null
            && $gate->event_session_id !== $ticket->event_session_id) {
            return 'wrong_session';
        }

        if ($ticket->admitted_count >= $ticket->admits_total) {
            return 'duplicate';
        }

        if ($ticket->admitted_count + $partySize > $ticket->admits_total) {
            return 'over_capacity';
        }

        return 'admitted';
    }
}
