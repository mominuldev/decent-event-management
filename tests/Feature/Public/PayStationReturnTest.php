<?php

namespace Tests\Feature\Public;

use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayStationReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.frontend.url' => 'https://frontend.test']);
    }

    private function payment(): Payment
    {
        $registration = Registration::factory()->create();

        return Payment::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $registration->attendee_id,
            'method' => 'paystation',
            'channel' => 'online',
            'status' => 'initiated',
        ]);
    }

    public function test_it_redirects_to_the_registration_page_on_the_configured_frontend(): void
    {
        $payment = $this->payment();

        $this->get(route('api.v1.public.payments.paystation.return', ['payment' => $payment->ulid]).'?trx_id=CG20D8AYB4')
            ->assertRedirect("https://frontend.test/registrations/{$payment->registration->ulid}?payment_status=success");
    }

    /**
     * The redirect origin comes from config alone — there is no `next`
     * parameter to point somewhere else, which is why this route cannot be
     * turned into an open redirect the way a query-driven one could.
     */
    public function test_a_supplied_next_parameter_is_ignored_entirely(): void
    {
        $payment = $this->payment();

        $this->get(route('api.v1.public.payments.paystation.return', ['payment' => $payment->ulid])
            .'?next=https://evil.example/steal&trx_id=CG20D8AYB4')
            ->assertRedirect("https://frontend.test/registrations/{$payment->registration->ulid}?payment_status=success");
    }

    /**
     * PayStation uses one callback URL for every outcome, so the hint is
     * derived from whether a transaction id came back. It decides only
     * whether the page polls — the banner it shows comes from the
     * registration's server-side status.
     */
    public function test_a_return_with_no_transaction_id_still_asks_the_page_to_poll(): void
    {
        $payment = $this->payment();

        $this->get(route('api.v1.public.payments.paystation.return', ['payment' => $payment->ulid]))
            ->assertRedirect("https://frontend.test/registrations/{$payment->registration->ulid}?payment_status=fail");
    }

    public function test_it_accepts_a_form_post_return_as_well_as_a_redirect(): void
    {
        $payment = $this->payment();

        $this->post(route('api.v1.public.payments.paystation.return', ['payment' => $payment->ulid]), [
            'trx_id' => 'CG20D8AYB4',
            'invoice_number' => $payment->payment_number,
        ])->assertRedirect("https://frontend.test/registrations/{$payment->registration->ulid}?payment_status=success");
    }

    /** A browser return proves nothing and must never move a payment (docs/06 §6.6). */
    public function test_it_never_changes_the_payment(): void
    {
        $payment = $this->payment();

        $this->get(route('api.v1.public.payments.paystation.return', ['payment' => $payment->ulid])
            .'?trx_id=CG20D8AYB4&trx_status=Success&trx_amount=999999');

        $fresh = $payment->fresh();

        $this->assertSame('initiated', $fresh?->status);
        $this->assertNull($fresh?->paid_at);
        $this->assertNull($fresh?->gateway_transaction_id);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_an_unknown_payment_is_not_found(): void
    {
        $this->get(route('api.v1.public.payments.paystation.return', ['payment' => '01JQZZZZZZZZZZZZZZZZZZZZZZ']))
            ->assertNotFound();
    }
}
