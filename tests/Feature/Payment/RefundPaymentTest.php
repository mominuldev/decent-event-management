<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Actions\RefundPayment;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RefundPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_refund_calls_the_gateway_and_voids_the_ticket(): void
    {
        $ticketType = TicketType::factory()->create(['quantity_sold' => 1]);
        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->for($ticketType)->for($attendee)->create(['status' => 'confirmed']);

        $payment = Payment::factory()->for($registration)->for($attendee)->create([
            'status' => 'succeeded',
            'method' => 'bkash',
            'amount_due_paisa' => 50000,
            'refunded_paisa' => 0,
        ]);

        $ticket = Ticket::factory()->for($registration)->for($attendee)->for($ticketType)->create([
            'status' => 'active',
            'price_paid_paisa' => 50000,
        ]);

        $approver = User::factory()->create();

        $refund = app(RefundPayment::class)->execute($payment, $approver, 'attendee requested', null, 'full');

        $this->assertEquals(50000, $refund->amount_paisa);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'refunded',
            'refunded_paisa' => 50000,
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'type' => 'refund',
            'status' => 'success',
            'amount_paisa' => 50000,
        ]);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'voided',
        ]);

        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'status' => 'refunded',
        ]);
    }

    public function test_a_manual_payment_refund_never_calls_a_gateway(): void
    {
        Http::preventStrayRequests();

        $ticketType = TicketType::factory()->create(['quantity_sold' => 1]);
        $registration = Registration::factory()->for($ticketType)->create();

        $payment = Payment::factory()->for($registration)->create([
            'status' => 'succeeded',
            'method' => 'bkash',
            'channel' => 'manual',
            'amount_due_paisa' => 50000,
        ]);

        $approver = User::factory()->create();

        $refund = app(RefundPayment::class)->execute($payment, $approver, 'duplicate manual entry', null, 'full');

        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'type' => 'refund',
            'gateway_reference' => null,
        ]);

        $this->assertNotNull($refund->id);
    }

    public function test_an_sslcommerz_refund_without_a_recorded_bank_transaction_id_fails_closed(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'succeeded',
            'method' => 'sslcommerz',
            'gateway_transaction_id' => null,
            'amount_due_paisa' => 50000,
        ]);

        $approver = User::factory()->create();

        $this->expectException(RuntimeException::class);

        app(RefundPayment::class)->execute($payment, $approver, 'attendee requested', null, 'full');
    }
}
