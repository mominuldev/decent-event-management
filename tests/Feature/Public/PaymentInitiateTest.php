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
            'base_price_paisa' => 100000,
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
