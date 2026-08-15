<?php

namespace Tests\Feature\Public;

use App\Domain\Payment\Actions\InitiatePayment;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /public/registrations/{registration}/payment/verify` — the
 * return-from-gateway page's way of asking the server to re-check
 * settlement.
 *
 * It exists because the read endpoint alone only reflects what an IPN has
 * already written, and an IPN can be delayed or lost (and cannot reach a
 * localhost dev server at all), which left the "Confirming your payment"
 * screen spinning on a payment that had genuinely settled.
 *
 * The invariant it must never break: the browser arriving back is a prompt
 * to ask the gateway, never evidence of payment. This endpoint takes no
 * request body at all, so there is nothing a caller could assert.
 */
class PaymentVerifyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function registrationWithPayment(string $paymentStatus = 'pending'): Registration
    {
        $attendee = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create(['base_price_paisa' => 100000]);

        $registration = Registration::factory()->for($ticketType)->for($attendee)->create([
            'status' => 'pending_payment',
            'total_paisa' => 100000,
        ]);

        $payment = Payment::factory()->for($registration)->for($attendee)->create([
            'status' => $paymentStatus,
            // FakeGateway: deterministic, so the outcome under test is the
            // endpoint's behaviour rather than a live gateway's mood.
            'method' => 'bkash',
            'amount_due_paisa' => 100000,
        ]);

        // Open the gateway session first — the same order the real flow
        // uses, and what gives the fake a session to report on.
        app(InitiatePayment::class)->handle($payment, 'https://frontend.test/registrations/'.$registration->ulid);

        return $registration;
    }

    public function test_it_settles_a_payment_the_gateway_reports_as_paid(): void
    {
        $registration = $this->registrationWithPayment();

        $response = $this->postJson(
            route('api.v1.public.registrations.payment.verify', ['registration' => $registration->ulid])
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.outcome', 'succeeded');

        $this->assertSame('succeeded', $registration->payments()->first()->fresh()->status);
        $this->assertNotSame('pending_payment', $registration->fresh()->status);
    }

    /**
     * The page polls this every few seconds. A second call on an already
     * settled payment must be a no-op rather than a second gateway round
     * trip or an illegal state transition.
     */
    public function test_polling_an_already_settled_payment_is_a_no_op(): void
    {
        $registration = $this->registrationWithPayment();
        $route = route('api.v1.public.registrations.payment.verify', ['registration' => $registration->ulid]);

        $this->postJson($route)->assertStatus(200)->assertJsonPath('data.outcome', 'succeeded');

        $this->postJson($route)
            ->assertStatus(200)
            ->assertJsonPath('data.outcome', 'settled');
    }

    public function test_it_takes_no_request_body_into_account(): void
    {
        $registration = $this->registrationWithPayment();

        // A caller asserting its own success must change nothing: the
        // outcome comes from the gateway lookup, not the payload.
        $response = $this->postJson(
            route('api.v1.public.registrations.payment.verify', ['registration' => $registration->ulid]),
            ['status' => 'succeeded', 'amount_paid_paisa' => 999999, 'payment_status' => 'success'],
        );

        $response->assertStatus(200);

        $payment = $registration->payments()->first()->fresh();

        $this->assertSame(100000, $payment->amount_due_paisa, 'the request body must not touch the amount');
        $this->assertNotSame(999999, $payment->amount_paid_paisa);
    }

    public function test_it_404s_for_an_unknown_registration(): void
    {
        $this->postJson(
            route('api.v1.public.registrations.payment.verify', ['registration' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'])
        )->assertStatus(404);
    }
}
