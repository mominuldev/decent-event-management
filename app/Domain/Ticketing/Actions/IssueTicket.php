<?php

namespace App\Domain\Ticketing\Actions;

use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Events\TicketIssued;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\QrSigner;
use App\Domain\Ticketing\Services\TicketNumberGenerator;
use Illuminate\Support\Facades\DB;

class IssueTicket
{
    public function __construct(
        private readonly TicketNumberGenerator $ticketNumbers,
        private readonly QrSigner $qrSigner,
    ) {}

    public function execute(Registration $registration): Ticket
    {
        return DB::transaction(function () use ($registration): Ticket {
            $attendee = $registration->attendee;
            $ticketType = $registration->ticketType;
            $admitsTotal = $registration->adults_count + $registration->children_count;

            $batchYear = $attendee?->ssc_batch_year !== null ? (string) $attendee->ssc_batch_year : 'XXXX';

            $seq = str_pad((string) $this->ticketNumbers->next((int) $ticketType?->id, $batchYear), 5, '0', STR_PAD_LEFT);
            $ticketNumber = "DEC100-{$ticketType?->code}-{$batchYear}-{$seq}";

            $ticket = Ticket::create([
                'registration_id' => $registration->id,
                'attendee_id' => $attendee?->id,
                'ticket_type_id' => $ticketType?->id,
                'event_session_id' => $registration->event_session_id,
                'ticket_number' => $ticketNumber,
                'status' => 'issued',
                'admitted_count' => 0,
                'admits_total' => $admitsTotal,
                'holder_name' => $attendee?->full_name,
                'holder_batch_year' => $attendee?->ssc_batch_year,
                'holder_type_label' => $attendee?->participant_type,
                'price_paid_paisa' => $registration->total_paisa,
                'currency' => $registration->currency ?? 'BDT',
            ]);

            $expiresAt = now()->addYear();
            $eventSession = $registration->eventSession;
            if ($eventSession !== null) {
                $expiresAt = $eventSession->ends_at;
            }
            $expUnix = (int) $expiresAt->timestamp;

            $signed = $this->qrSigner->sign($ticket->ulid, $admitsTotal, $expUnix);

            $ticket->qrCode()->create([
                'payload_version' => 1,
                'payload' => $signed['payload'],
                'payload_hash' => $signed['payload_hash'],
                'signature' => $signed['signature'],
                'signing_key_id' => $signed['signing_key_id'],
                'issued_at' => now(),
                'expires_at' => $expiresAt,
                'is_active' => true,
            ]);

            $ticket->transitionTo('active');
            $ticket->issued_at = now();
            $ticket->save();

            $registration->transitionTo('confirmed');
            $registration->save();

            TicketIssued::dispatch($ticket);

            return $ticket;
        });
    }
}
