<?php

namespace Tests\Feature\Public;

use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentInitiateTest extends TestCase
{
    use RefreshDatabase;

    private function createRegistration(): Registration
    {
        $ticketType = TicketType::factory()->create([
            'base_price_tk' => 100000,
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
        ]);

        $attendee = Attendee::factory()->create();

        return Registration::factory()
            ->for($ticketType)
            ->for($attendee)
            ->create(['status' => 'pending_payment']);
    }

    public function test_initiate_creates_a_gateway_session_for_the_pending_payment(): void
    {
        $registration = $this->createRegistration();

        Payment::factory()->for($registration)->for($registration->attendee)->create([
            'status' => 'pending',
            'method' => 'bkash',
            'amount_due_paisa' => $registration->total_paisa,
        ]);

        $response = $this->withHeader('Idempotency-Key', 'initiate-test-key-1')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]));

        $response->assertStatus(200)
            ->assertJsonPath('data.payment.status', 'initiated');

        $this->assertNotEmpty($response->json('data.redirect_url'));

        $this->assertDatabaseHas('payments', [
            'registration_id' => $registration->id,
            'status' => 'initiated',
        ]);
    }

    public function test_initiate_requires_idempotency_key_header(): void
    {
        $registration = $this->createRegistration();

        Payment::factory()->for($registration)->for($registration->attendee)->create([
            'status' => 'pending',
            'method' => 'bkash',
            'amount_due_paisa' => $registration->total_paisa,
        ]);

        $response = $this->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]));

        $response->assertStatus(400);
    }

    public function test_initiate_rejects_a_registration_with_no_payable_payment(): void
    {
        $registration = $this->createRegistration();

        Payment::factory()->for($registration)->for($registration->attendee)->create([
            'status' => 'succeeded',
            'method' => 'bkash',
            'amount_due_paisa' => $registration->total_paisa,
        ]);

        $response = $this->withHeader('Idempotency-Key', 'initiate-test-key-2')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'no_payable_payment');
    }

    /**
     * The payer closed the checkout tab and came back. The old row is
     * single-use at the gateway, so the click opens a fresh one — and the
     * seat, which was never released, simply follows it.
     */
    public function test_initiate_after_an_abandoned_checkout_opens_a_fresh_payment_and_keeps_the_seat(): void
    {
        $registration = $this->createRegistration();
        $registration->ticketType->update(['quantity_total' => 1, 'quantity_reserved' => 1]);

        $abandoned = Payment::factory()->for($registration)->for($registration->attendee)->create([
            'status' => 'initiated',
            'method' => 'bkash',
            'gateway_reference' => 'FAKE-NEVER-FINISHED',
            'amount_due_paisa' => $registration->total_paisa,
        ]);

        $response = $this->withHeader('Idempotency-Key', 'initiate-test-key-4')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]));

        $response->assertStatus(200)
            ->assertJsonPath('data.payment.status', 'initiated');

        $this->assertNotEquals($abandoned->payment_number, $response->json('data.payment.payment_number'));
        $this->assertDatabaseHas('payments', ['id' => $abandoned->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, $registration->ticketType->fresh()->quantity_reserved);
    }

    public function test_initiate_after_a_declined_attempt_re_reserves_the_seat(): void
    {
        $registration = $this->createRegistration();
        // markFailed already gave the seat back.
        $registration->ticketType->update(['quantity_total' => 1, 'quantity_reserved' => 0]);

        Payment::factory()->for($registration)->for($registration->attendee)->create([
            'status' => 'failed',
            'method' => 'bkash',
            'amount_due_paisa' => $registration->total_paisa,
        ]);

        $this->withHeader('Idempotency-Key', 'initiate-test-key-5')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]))
            ->assertStatus(200)
            ->assertJsonPath('data.payment.status', 'initiated');

        $this->assertSame(1, $registration->ticketType->fresh()->quantity_reserved);
    }

    public function test_a_retry_is_refused_when_the_released_seat_has_since_been_sold(): void
    {
        $registration = $this->createRegistration();
        $registration->ticketType->update(['quantity_total' => 1, 'quantity_reserved' => 0, 'quantity_sold' => 1]);

        Payment::factory()->for($registration)->for($registration->attendee)->create([
            'status' => 'expired',
            'method' => 'bkash',
            'amount_due_paisa' => $registration->total_paisa,
        ]);

        $this->withHeader('Idempotency-Key', 'initiate-test-key-6')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'sold_out');

        $this->assertDatabaseCount('payments', 1);
    }

    /**
     * The IPN never arrived, but the gateway says the abandoned attempt
     * settled — pressing Pay again must confirm it, not charge twice.
     */
    public function test_a_retry_on_a_paid_but_unnoticed_attempt_settles_it_instead_of_charging_again(): void
    {
        $registration = $this->createRegistration();

        Payment::factory()->for($registration)->for($registration->attendee)->create([
            'status' => 'pending',
            'method' => 'bkash',
            'amount_due_paisa' => $registration->total_paisa,
        ]);

        // The fake gateway records the session as paid the moment it is
        // opened, so this stands in for "paid, IPN lost".
        $this->withHeader('Idempotency-Key', 'initiate-test-key-7a')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]))
            ->assertStatus(200);

        $this->withHeader('Idempotency-Key', 'initiate-test-key-7b')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_paid');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['registration_id' => $registration->id, 'status' => 'succeeded']);
        // `paid`, or already `confirmed` once ticket issuance has run.
        $this->assertContains($registration->fresh()->status, ['paid', 'confirmed']);
    }

    public function test_a_retried_initiate_with_the_same_key_replays_the_cached_response(): void
    {
        $registration = $this->createRegistration();

        Payment::factory()->for($registration)->for($registration->attendee)->create([
            'status' => 'pending',
            'method' => 'bkash',
            'amount_due_paisa' => $registration->total_paisa,
        ]);

        $first = $this->withHeader('Idempotency-Key', 'initiate-test-key-3')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]));
        $first->assertStatus(200);

        $second = $this->withHeader('Idempotency-Key', 'initiate-test-key-3')
            ->postJson(route('api.v1.public.registrations.payment.initiate', ['registration' => $registration->ulid]));
        $second->assertStatus(200);

        $this->assertEquals(
            $first->json('data.redirect_url'),
            $second->json('data.redirect_url'),
        );

        $this->assertDatabaseCount('payments', 1);
    }
}
