<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Actions\RefundPayment;
use App\Domain\Payment\Exceptions\OutOfBandRefundRequiredException;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    /**
     * PayStation publishes no refund API, so a refund there happens in
     * their merchant panel and this system only records it. Recording one
     * unasked would void the attendee's ticket and release their seat
     * while their money is still with the gateway — so it refuses.
     */
    public function test_a_paystation_refund_is_refused_without_an_out_of_band_acknowledgement(): void
    {
        $payment = $this->payStationPayment();

        $this->expectException(OutOfBandRefundRequiredException::class);

        app(RefundPayment::class)->execute($payment, User::factory()->create(), 'attendee requested', null, 'full');
    }

    /** Ticking the box without the gateway's own reference is not evidence. */
    public function test_a_paystation_refund_is_refused_when_acknowledged_with_no_gateway_reference(): void
    {
        $payment = $this->payStationPayment();

        $this->expectException(OutOfBandRefundRequiredException::class);

        app(RefundPayment::class)->execute(
            $payment,
            User::factory()->create(),
            'attendee requested',
            null,
            'full',
            acknowledgedOutOfBand: true,
            gatewayRefundReference: '   ',
        );
    }

    public function test_an_acknowledged_paystation_refund_records_the_gateway_reference(): void
    {
        $payment = $this->payStationPayment();

        $refund = app(RefundPayment::class)->execute(
            $payment,
            User::factory()->create(),
            'attendee requested',
            null,
            'full',
            acknowledgedOutOfBand: true,
            gatewayRefundReference: 'PS-REFUND-77219',
        );

        $this->assertSame('PS-REFUND-77219', $refund->gateway_refund_id);

        // Regression: these four are outside Refund::$fillable, so the
        // previous Refund::create() dropped all of them — every refund ever
        // recorded was missing who approved it and when.
        $stored = $refund->fresh();
        $this->assertSame('PS-REFUND-77219', $stored?->gateway_refund_id);
        $this->assertNotNull($stored?->approved_by_user_id);
        $this->assertNotNull($stored?->approved_at);
        $this->assertNotNull($stored?->processed_at);

        // Not `success`: no request was made to a gateway, and a row
        // claiming one would read as gateway evidence during a dispute.
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'type' => 'refund',
            'status' => 'acknowledged_out_of_band',
            'gateway_reference' => 'PS-REFUND-77219',
        ]);

        $this->assertSame('refunded', $payment->fresh()?->status);
    }

    /** Nothing may be written when the acknowledgement is missing. */
    public function test_a_refused_paystation_refund_leaves_the_payment_untouched(): void
    {
        $payment = $this->payStationPayment();

        try {
            app(RefundPayment::class)->execute($payment, User::factory()->create(), 'attendee requested');
        } catch (OutOfBandRefundRequiredException) {
            // expected
        }

        $this->assertSame('succeeded', $payment->fresh()?->status);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseMissing('payment_transactions', [
            'payment_id' => $payment->id,
            'type' => 'refund',
        ]);
    }

    private function payStationPayment(): Payment
    {
        return Payment::factory()->create([
            'status' => 'succeeded',
            'method' => 'paystation',
            'channel' => 'online',
            'amount_due_paisa' => 50000,
            'amount_paid_paisa' => 50000,
            'gateway_transaction_id' => 'CG20D8AYB4',
        ]);
    }
}
