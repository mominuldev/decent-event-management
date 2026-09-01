<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FindStuckPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private TicketType $ticketType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticketType = TicketType::factory()->create([
            'quantity_total' => 1000,
            'quantity_reserved' => 5,
            'quantity_sold' => 0,
        ]);
    }

    public function test_it_lists_only_online_payments_that_have_not_settled(): void
    {
        $stuck = $this->payment('initiated', 'sslcommerz', 'online');
        $settled = $this->payment('succeeded', 'sslcommerz', 'online');
        $manual = $this->payment('pending', 'bkash', 'manual');

        $this->artisan('payments:stuck')
            ->expectsOutputToContain($stuck->payment_number)
            ->doesntExpectOutputToContain($settled->payment_number)
            ->doesntExpectOutputToContain($manual->payment_number)
            ->assertSuccessful();
    }

    /**
     * The listing must be safe to run during an incident, so the default
     * asks no gateway anything and cannot change a payment's state.
     */
    public function test_listing_without_check_touches_no_gateway_and_writes_nothing(): void
    {
        $payment = $this->payment('initiated', 'bkash', 'online');

        $this->artisan('payments:stuck')
            ->expectsOutputToContain('Nothing was asked of any gateway')
            ->assertSuccessful();

        $this->assertSame('initiated', $payment->fresh()?->status);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_check_reports_a_payment_the_gateway_has_already_taken_without_settling_it(): void
    {
        $payment = $this->paidAtGateway();

        $this->artisan('payments:stuck --check')
            ->expectsOutputToContain('PAID AT GATEWAY')
            ->expectsOutputToContain('1 confirmed paid at the gateway.')
            ->assertSuccessful();

        // Reported, not resolved — settling is an explicit second step.
        $this->assertSame('initiated', $payment->fresh()?->status);
    }

    public function test_recover_settles_a_confirmed_payment_and_issues_its_ticket(): void
    {
        $payment = $this->paidAtGateway();

        $this->artisan('payments:stuck --recover')
            ->expectsOutputToContain('1 settled.')
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', [
            'ulid' => $payment->ulid,
            'status' => 'succeeded',
            'amount_paid_paisa' => 50000,
        ]);

        // `confirmed`, not `paid`: issuing the ticket carries the
        // registration the rest of the way, which is the point of the
        // recovery — the payer ends up where a clean checkout would have
        // left them, not merely paid up.
        $this->assertDatabaseHas('registrations', [
            'ulid' => $payment->registration?->ulid,
            'status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('tickets', ['registration_id' => $payment->registration_id]);
    }

    private function paidAtGateway(): Payment
    {
        $payment = $this->payment('initiated', 'bkash', 'online');

        $gatewayReference = 'FAKE-STUCK-'.strtoupper(bin2hex(random_bytes(8)));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'succeeded',
            'amount_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN-STUCK',
        ]);

        $payment->update(['gateway_reference' => $gatewayReference]);

        return $payment->fresh() ?? $payment;
    }

    private function payment(string $status, string $method, string $channel): Payment
    {
        $attendee = Attendee::factory()->create();

        $registration = Registration::factory()
            ->for($this->ticketType)
            ->for($attendee)
            ->create(['status' => $status === 'succeeded' ? 'paid' : 'pending_payment']);

        return Payment::factory()
            ->for($registration)
            ->for($attendee)
            ->create([
                'status' => $status,
                'method' => $method,
                'channel' => $channel,
                'amount_due_paisa' => 50000,
            ]);
    }
}
