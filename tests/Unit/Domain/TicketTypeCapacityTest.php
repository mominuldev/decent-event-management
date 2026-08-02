<?php

namespace Tests\Unit\Domain;

use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/03 §3.7: sold/reserved counters use the same race-free conditional
 * UPDATE pattern as ticket admission (ADR-04).
 */
class TicketTypeCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_succeeds_while_capacity_remains(): void
    {
        $type = TicketType::factory()->create(['quantity_total' => 2, 'quantity_sold' => 0, 'quantity_reserved' => 0]);

        $this->assertTrue($type->tryReserve(1));
        $this->assertTrue($type->fresh()->tryReserve(1));
        $this->assertFalse($type->fresh()->tryReserve(1));

        $this->assertSame(2, $type->fresh()->quantity_reserved);
    }

    public function test_unlimited_quantity_always_reserves(): void
    {
        $type = TicketType::factory()->create(['quantity_total' => null, 'quantity_sold' => 0, 'quantity_reserved' => 0]);

        $this->assertTrue($type->tryReserve(500));
    }

    public function test_reserve_accounts_for_both_sold_and_reserved(): void
    {
        $type = TicketType::factory()->create(['quantity_total' => 10, 'quantity_sold' => 8, 'quantity_reserved' => 1]);

        $this->assertTrue($type->tryReserve(1));
        $this->assertFalse($type->fresh()->tryReserve(1));
    }
}
