<?php

namespace Tests\Unit\Domain;

use App\Domain\Notification\Models\Notification;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Support\InvalidStateTransitionException;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every transition drawn in docs/04 §4.7 must be permitted; every
 * transition not drawn there must be rejected.
 */
class StateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_follows_the_documented_state_machine(): void
    {
        $registration = Registration::factory()->create(['status' => 'draft']);

        $this->assertTrue($registration->canTransitionTo('pending_payment'));
        $this->assertTrue($registration->canTransitionTo('expired'));
        $this->assertFalse($registration->canTransitionTo('confirmed'));
        $this->assertFalse($registration->canTransitionTo('refunded'));

        $registration->transitionTo('pending_payment');
        $this->assertSame('pending_payment', $registration->fresh()->status);
    }

    public function test_registration_rejects_an_undrawn_transition(): void
    {
        $registration = Registration::factory()->create(['status' => 'draft']);

        $this->expectException(InvalidStateTransitionException::class);

        $registration->transitionTo('confirmed');
    }

    public function test_expired_registration_can_retry_to_pending_payment(): void
    {
        $registration = Registration::factory()->create(['status' => 'expired']);

        $this->assertTrue($registration->canTransitionTo('pending_payment'));
    }

    public function test_payment_only_reaches_succeeded_from_processing_or_manual_verification(): void
    {
        $pending = Payment::factory()->create(['status' => 'pending']);
        $this->assertFalse($pending->canTransitionTo('succeeded'));

        $processing = Payment::factory()->create(['status' => 'processing']);
        $this->assertTrue($processing->canTransitionTo('succeeded'));

        $awaitingVerification = Payment::factory()->create(['status' => 'awaiting_verification']);
        $this->assertTrue($awaitingVerification->canTransitionTo('succeeded'));
    }

    public function test_payment_terminal_states_have_no_further_transitions(): void
    {
        $failed = Payment::factory()->create(['status' => 'failed']);
        $this->assertFalse($failed->canTransitionTo('succeeded'));
        $this->assertFalse($failed->canTransitionTo('processing'));
    }

    public function test_ticket_supports_partial_admission_before_full_admission(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active']);

        $this->assertTrue($ticket->canTransitionTo('partially_admitted'));
        $this->assertTrue($ticket->canTransitionTo('fully_admitted'));
        $this->assertFalse($ticket->canTransitionTo('issued'));

        $ticket->transitionTo('partially_admitted');
        $this->assertTrue($ticket->fresh()->canTransitionTo('fully_admitted'));
        $this->assertTrue($ticket->fresh()->canTransitionTo('voided'));
    }

    public function test_voided_ticket_is_terminal(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'voided']);

        $this->assertFalse($ticket->canTransitionTo('active'));
        $this->assertFalse($ticket->canTransitionTo('refunded'));
    }

    public function test_notification_retry_and_terminal_paths(): void
    {
        $sending = Notification::factory()->create(['status' => 'sending']);
        $this->assertTrue($sending->canTransitionTo('queued'));
        $this->assertTrue($sending->canTransitionTo('failed'));

        $failed = Notification::factory()->create(['status' => 'failed']);
        $this->assertFalse($failed->canTransitionTo('sent'));
    }
}
