<?php

namespace Tests\Feature\Ticketing;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ticket number is `{TICKET TYPE CODE}-{5-DIGIT SEQUENCE}` — `CEN-00001`
 * — as of 2026-09-11. It was `DEC100-CEN-2005-00001`.
 *
 * It is not what admits anyone (the QR carries the ticket's ULID under an
 * Ed25519 signature); it is the human handle, printed on the A5 PDF, shown
 * on the email counterfoil, sent in the one-segment ticket SMS, searched in
 * the admin console and read down a phone. Length is what those last three
 * pay for, which is why the constant `DEC100-` prefix and the batch year —
 * still on the row as `holder_batch_year` — came out.
 */
class TicketNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ticket_number_is_its_type_code_and_a_five_digit_sequence(): void
    {
        $ticketType = TicketType::factory()->create(['code' => 'CEN']);

        $ticket = app(IssueTicket::class)->execute($this->registrationFor($ticketType, 2005));

        $this->assertSame('CEN-00001', $ticket->ticket_number);
        $this->assertSame(9, strlen($ticket->ticket_number));
    }

    /**
     * The batch year is gone from the string, so it must also be gone from
     * the counter's scope: a per-batch counter would mint `CEN-00001` once
     * for 2005 and again for 1998, and the collision would surface only as
     * a raw `uk_tickets_number` violation at issuance.
     */
    public function test_two_holders_from_different_batch_years_get_consecutive_numbers(): void
    {
        $ticketType = TicketType::factory()->create(['code' => 'CEN']);

        $first = app(IssueTicket::class)->execute($this->registrationFor($ticketType, 2005));
        $second = app(IssueTicket::class)->execute($this->registrationFor($ticketType, 1998));
        $third = app(IssueTicket::class)->execute($this->registrationFor($ticketType, null));

        $this->assertSame(['CEN-00001', 'CEN-00002', 'CEN-00003'], [
            $first->ticket_number,
            $second->ticket_number,
            $third->ticket_number,
        ]);
    }

    /** A holder with no batch year no longer drags an `XXXX` into the number. */
    public function test_a_missing_batch_year_leaves_no_placeholder_in_the_number(): void
    {
        $ticketType = TicketType::factory()->create(['code' => 'ALM']);

        $ticket = app(IssueTicket::class)->execute($this->registrationFor($ticketType, null));

        $this->assertSame('ALM-00001', $ticket->ticket_number);
        $this->assertStringNotContainsString('XXXX', $ticket->ticket_number);
        $this->assertStringNotContainsString('DEC100', $ticket->ticket_number);
    }

    public function test_each_ticket_type_numbers_its_own_series(): void
    {
        $cen = TicketType::factory()->create(['code' => 'CEN']);
        $vip = TicketType::factory()->create(['code' => 'VIP']);

        $this->assertSame('CEN-00001', app(IssueTicket::class)->execute($this->registrationFor($cen, 2005))->ticket_number);
        $this->assertSame('VIP-00001', app(IssueTicket::class)->execute($this->registrationFor($vip, 2005))->ticket_number);
        $this->assertSame('CEN-00002', app(IssueTicket::class)->execute($this->registrationFor($cen, 1998))->ticket_number);
    }

    /**
     * The batch year is dropped from the *number*, not from the ticket —
     * the gate list and the printed ticket still show it.
     */
    public function test_the_batch_year_is_still_snapshotted_on_the_ticket(): void
    {
        $ticketType = TicketType::factory()->create(['code' => 'CEN']);

        $ticket = app(IssueTicket::class)->execute($this->registrationFor($ticketType, 2005));

        $this->assertSame(2005, $ticket->holder_batch_year);
    }

    private function registrationFor(TicketType $ticketType, ?int $batchYear): Registration
    {
        // AttendeeFactory clears `ssc_batch_year` for anyone who is not a
        // student — a teacher has no SSC batch — so the participant type
        // has to agree with the year being asked for here.
        $attendee = Attendee::factory()->create([
            'participant_type' => $batchYear === null ? 'teacher' : 'former_student',
            'ssc_batch_year' => $batchYear,
        ]);

        return Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);
    }
}
