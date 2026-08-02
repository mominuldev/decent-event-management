<?php

namespace Tests\Unit\Domain;

use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-04: admission is a single atomic conditional UPDATE, not a
 * read-then-write. These are boundary-condition tests; the actual
 * multi-process concurrency drill (300 concurrent purchases, 20
 * concurrent scans) is a Phase 8 load-testing exercise — docs/08 §Phase 8.
 */
class TicketAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admits_up_to_admits_total_and_no_further(): void
    {
        $ticket = Ticket::factory()->create(['admits_total' => 4, 'admitted_count' => 0, 'status' => 'active']);

        $this->assertTrue($ticket->tryAdmit(2));
        $this->assertSame(2, $ticket->fresh()->admitted_count);
        $this->assertSame('partially_admitted', $ticket->fresh()->status);

        $this->assertTrue($ticket->fresh()->tryAdmit(2));
        $this->assertSame(4, $ticket->fresh()->admitted_count);
        $this->assertSame('fully_admitted', $ticket->fresh()->status);

        // Duplicate: no capacity left.
        $this->assertFalse($ticket->fresh()->tryAdmit(1));
        $this->assertSame(4, $ticket->fresh()->admitted_count);
    }

    public function test_rejects_a_party_larger_than_remaining_capacity(): void
    {
        $ticket = Ticket::factory()->create(['admits_total' => 4, 'admitted_count' => 3, 'status' => 'active']);

        $this->assertFalse($ticket->tryAdmit(2));
        $this->assertSame(3, $ticket->fresh()->admitted_count);
    }

    public function test_single_admit_ticket_goes_straight_to_fully_admitted(): void
    {
        $ticket = Ticket::factory()->create(['admits_total' => 1, 'admitted_count' => 0, 'status' => 'active']);

        $this->assertTrue($ticket->tryAdmit(1));
        $this->assertSame('fully_admitted', $ticket->fresh()->status);
    }

    public function test_first_admission_sets_first_admitted_at_and_further_admissions_do_not_move_it(): void
    {
        $ticket = Ticket::factory()->create(['admits_total' => 4, 'admitted_count' => 0, 'status' => 'active']);

        $ticket->tryAdmit(1);
        $firstAdmittedAt = $ticket->fresh()->first_admitted_at;
        $this->assertNotNull($firstAdmittedAt);

        $ticket->fresh()->tryAdmit(1);
        $this->assertEquals($firstAdmittedAt, $ticket->fresh()->first_admitted_at);
    }

    public function test_admission_bumps_manifest_version_for_scanner_delta_sync(): void
    {
        $ticket = Ticket::factory()->create(['admits_total' => 4, 'admitted_count' => 0, 'manifest_version' => 1]);

        $ticket->tryAdmit(1);

        $this->assertSame(2, $ticket->fresh()->manifest_version);
    }
}
