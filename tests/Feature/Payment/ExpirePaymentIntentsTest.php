<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Actions\ExpirePaymentIntents;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExpirePaymentIntentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_abandoned_intent_past_its_ttl_is_expired_and_releases_capacity(): void
    {
        $ticketType = TicketType::factory()->create([
            'quantity_total' => 100,
            'quantity_reserved' => 1,
            'quantity_sold' => 0,
        ]);
        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->for($ticketType)->for($attendee)->create(['status' => 'pending_payment']);

        $payment = Payment::factory()->for($registration)->for($attendee)->create([
            'status' => 'pending',
            'method' => 'bkash',
            'gateway_reference' => null,
            'expires_at' => now()->subMinutes(5),
        ]);

        $result = app(ExpirePaymentIntents::class)->handle();

        $this->assertEquals(1, $result['expired']);
        $this->assertEquals(0, $result['recovered']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'expired']);
        $this->assertDatabaseHas('ticket_types', ['id' => $ticketType->id, 'quantity_reserved' => 0]);
    }

    public function test_a_late_ipn_is_recovered_instead_of_expired(): void
    {
        $ticketType = TicketType::factory()->create([
            'quantity_total' => 100,
            'quantity_reserved' => 1,
            'quantity_sold' => 0,
        ]);
        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->for($ticketType)->for($attendee)->create(['status' => 'pending_payment']);

        $payment = Payment::factory()->for($registration)->for($attendee)->create([
            'status' => 'initiated',
            'method' => 'bkash',
            'gateway_reference' => 'FAKE-RECOVER-1',
            'amount_due_paisa' => 50000,
            'expires_at' => now()->subMinutes(5),
        ]);

        Cache::put('fake_gateway:session:FAKE-RECOVER-1', [
            'status' => 'succeeded',
            'amount_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN-RECOVER',
        ]);

        $result = app(ExpirePaymentIntents::class)->handle();

        $this->assertEquals(0, $result['expired']);
        $this->assertEquals(1, $result['recovered']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
        $this->assertTrue(Ticket::where('registration_id', $registration->id)->exists());
    }

    public function test_payments_not_yet_past_ttl_are_left_alone(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        $result = app(ExpirePaymentIntents::class)->handle();

        $this->assertEquals(0, $result['expired'] + $result['recovered']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_manual_channel_payments_are_not_swept(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'channel' => 'manual',
            'expires_at' => now()->subMinutes(5),
        ]);

        app(ExpirePaymentIntents::class)->handle();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }
}
