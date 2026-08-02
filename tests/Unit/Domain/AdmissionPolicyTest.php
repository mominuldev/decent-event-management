<?php

namespace Tests\Unit\Domain;

use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Services\AdmissionPolicy;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The manifest/scope/admission stages of the four-stage scan decision —
 * docs/01 §1.5, docs/04 §4.7.
 */
class AdmissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private AdmissionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new AdmissionPolicy;
    }

    public function test_voided_ticket_is_revoked(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'voided']);
        $gate = Gate::factory()->create();

        $this->assertSame('revoked', $this->policy->evaluate($ticket, $gate, 1));
    }

    public function test_unissued_ticket_is_unpaid(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'issued']);
        $gate = Gate::factory()->create();

        $this->assertSame('unpaid', $this->policy->evaluate($ticket, $gate, 1));
    }

    public function test_gate_restricted_to_other_ticket_types_is_wrong_gate(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active']);
        $gate = Gate::factory()->create(['allowed_ticket_type_ids' => [$ticket->ticket_type_id + 999]]);

        $this->assertSame('wrong_gate', $this->policy->evaluate($ticket, $gate, 1));
    }

    public function test_gate_with_no_restriction_allows_any_ticket_type(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active', 'admits_total' => 1, 'admitted_count' => 0]);
        $gate = Gate::factory()->create(['allowed_ticket_type_ids' => null]);

        $this->assertSame('admitted', $this->policy->evaluate($ticket, $gate, 1));
    }

    public function test_fully_admitted_ticket_is_duplicate(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active', 'admits_total' => 2, 'admitted_count' => 2]);
        $gate = Gate::factory()->create();

        $this->assertSame('duplicate', $this->policy->evaluate($ticket, $gate, 1));
    }

    public function test_party_larger_than_remaining_capacity_is_over_capacity(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active', 'admits_total' => 4, 'admitted_count' => 3]);
        $gate = Gate::factory()->create();

        $this->assertSame('over_capacity', $this->policy->evaluate($ticket, $gate, 2));
    }
}
