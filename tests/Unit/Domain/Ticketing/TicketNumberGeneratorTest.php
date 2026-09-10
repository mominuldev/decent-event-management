<?php

namespace Tests\Unit\Domain\Ticketing;

use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Services\TicketNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Replaces the O(n) lockForUpdate()->count() counter (docs/08 Phase 2
 * review, closed Phase 6) with an atomic per-ticket-type sequence.
 */
class TicketNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_starts_at_one_and_increments(): void
    {
        $ticketType = TicketType::factory()->create();
        $generator = new TicketNumberGenerator;

        $this->assertSame(1, $generator->next($ticketType->id));
        $this->assertSame(2, $generator->next($ticketType->id));
        $this->assertSame(3, $generator->next($ticketType->id));
    }

    /**
     * The inverse of what this asserted before 2026-09-11. A ticket number
     * no longer carries the batch year, so a counter that restarted per
     * batch would hand `CEN-00001` to one holder from 1998 and another
     * from 2005 — a duplicate that only surfaces as a raw
     * `uk_tickets_number` violation at issuance time. The counter takes no
     * batch argument at all now, so what is asserted here is that it keeps
     * a single row; the end-to-end proof that two batch years share one
     * series is in TicketNumberFormatTest.
     */
    public function test_one_counter_serves_a_ticket_type_whatever_the_holders_batch_year(): void
    {
        $ticketType = TicketType::factory()->create();
        $generator = new TicketNumberGenerator;

        $this->assertSame(1, $generator->next($ticketType->id));
        $this->assertSame(2, $generator->next($ticketType->id));

        $this->assertSame(
            1,
            DB::table('ticket_number_sequences')->where('ticket_type_id', $ticketType->id)->count(),
            'The generator must keep exactly one counter row per ticket type.',
        );
    }

    public function test_each_ticket_type_has_its_own_independent_counter(): void
    {
        $typeA = TicketType::factory()->create();
        $typeB = TicketType::factory()->create();
        $generator = new TicketNumberGenerator;

        $this->assertSame(1, $generator->next($typeA->id));
        $this->assertSame(1, $generator->next($typeB->id));
        $this->assertSame(2, $generator->next($typeA->id));
    }
}
