<?php

namespace Tests\Unit\Domain\Ticketing;

use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Services\TicketNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Replaces the O(n) lockForUpdate()->count() counter (docs/08 Phase 2
 * review, closed Phase 6) with an atomic per-(type, batch) sequence.
 */
class TicketNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_starts_at_one_and_increments(): void
    {
        $ticketType = TicketType::factory()->create();
        $generator = new TicketNumberGenerator;

        $this->assertSame(1, $generator->next($ticketType->id, '1998'));
        $this->assertSame(2, $generator->next($ticketType->id, '1998'));
        $this->assertSame(3, $generator->next($ticketType->id, '1998'));
    }

    public function test_each_batch_label_has_its_own_independent_counter(): void
    {
        $ticketType = TicketType::factory()->create();
        $generator = new TicketNumberGenerator;

        $this->assertSame(1, $generator->next($ticketType->id, '1998'));
        $this->assertSame(1, $generator->next($ticketType->id, 'XXXX'));
        $this->assertSame(2, $generator->next($ticketType->id, '1998'));
        $this->assertSame(2, $generator->next($ticketType->id, 'XXXX'));
    }

    public function test_each_ticket_type_has_its_own_independent_counter(): void
    {
        $typeA = TicketType::factory()->create();
        $typeB = TicketType::factory()->create();
        $generator = new TicketNumberGenerator;

        $this->assertSame(1, $generator->next($typeA->id, '1998'));
        $this->assertSame(1, $generator->next($typeB->id, '1998'));
        $this->assertSame(2, $generator->next($typeA->id, '1998'));
    }
}
