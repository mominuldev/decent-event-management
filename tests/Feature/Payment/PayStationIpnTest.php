<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayStation's IPN carries no signature, so what stops a forged one from
 * minting a paid ticket is not authentication — it is that an IPN never
 * settles anything by itself. It only prompts a server-to-server
 * `verify()` against PayStation, and that answer is what decides. These
 * tests exist to keep that true.
 */
class PayStationIpnTest extends TestCase
{
    use RefreshDatabase;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.paystation.base_url' => 'https://sandbox.paystation.com.bd',
            'services.paystation.merchant_id' => '104-1653730183',
            'services.paystation.password' => 'gamecoderstorepass',
            'services.paystation.ipn_ip_allowlist' => [],
        ]);

        // pending_payment, not the factory's `draft`: that is the state a
        // registration is really in when an IPN arrives, and it is the only
        // one from which settlement can walk it to paid → confirmed.
        $registration = Registration::factory()->create(['status' => 'pending_payment']);

        $this->payment = Payment::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $registration->attendee_id,
            'method' => 'paystation',
            'channel' => 'online',
            'status' => 'initiated',
            'payment_number' => 'PAY-IPN00001',
            'gateway_reference' => 'PAY-IPN00001',
            'amount_due_paisa' => 250000,
            'currency' => 'BDT',
        ]);
    }

    private function fakeStatus(string $trxStatus, string $requestAmount = '2500.00'): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'message' => 'Transaction found',
                'data' => [
                    'invoice_number' => 'PAY-IPN00001',
                    'trx_status' => $trxStatus,
                    'trx_id' => 'CG20D8AYB4',
                    'request_amount' => $requestAmount,
                    'payment_amount' => $requestAmount,
                ],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ipn(array $overrides = []): array
    {
        return array_merge([
            'invoice_number' => 'PAY-IPN00001',
            'trx_status' => 'Success',
            'trx_id' => 'CG20D8AYB4',
            'trx_amount' => 2500,
            'order_date_time' => '2026-09-10 15:52:28',
            'payment_method' => 'bKash',
        ], $overrides);
    }

    public function test_an_ipn_settles_the_payment_only_after_the_gateway_confirms_it(): void
    {
        $this->fakeStatus('success');

        $this->postJson(route('webhooks.paystation'), $this->ipn())
            ->assertStatus(200)
            ->assertJsonPath('status', 'received');

        $this->assertSame('succeeded', $this->payment->fresh()?->status);

        // The settlement came from the status API, not from the body.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/transaction-status'));
    }

    /**
     * The whole security argument in one test: an IPN claiming success is
     * worthless if PayStation says the payment never completed.
     */
    public function test_an_ipn_claiming_success_cannot_settle_a_payment_the_gateway_says_is_unpaid(): void
    {
        $this->fakeStatus('processing');

        $this->postJson(route('webhooks.paystation'), $this->ipn())->assertStatus(200);

        $this->assertSame('initiated', $this->payment->fresh()?->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    /**
     * A forged amount in the body buys nothing: the amount checked is the
     * one PayStation reports when asked directly.
     */
    public function test_an_inflated_amount_in_the_body_is_ignored(): void
    {
        $this->fakeStatus('success', '1.00');

        $this->postJson(route('webhooks.paystation'), $this->ipn(['trx_amount' => 2500]))->assertStatus(200);

        $payment = $this->payment->fresh();

        $this->assertNotSame('succeeded', $payment?->status);
        $this->assertSame('amount_mismatch', $payment?->reconciliation_status);
    }

    /** Unsigned is recorded as NULL, not as a failed check. */
    public function test_the_recorded_transaction_marks_the_notification_unsigned(): void
    {
        $this->fakeStatus('success');

        $this->postJson(route('webhooks.paystation'), $this->ipn())->assertStatus(200);

        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $this->payment->id,
            'type' => 'ipn',
            'status' => 'unsigned',
            'signature_valid' => null,
            'gateway_transaction_id' => 'CG20D8AYB4',
        ]);
    }

    /** PayStation retries anything that is not a 2xx, so an unknown invoice still acknowledges. */
    public function test_an_unknown_invoice_is_acknowledged_and_ignored(): void
    {
        $this->postJson(route('webhooks.paystation'), $this->ipn(['invoice_number' => 'PAY-NOSUCH01']))
            ->assertStatus(200);

        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertSame('initiated', $this->payment->fresh()?->status);
    }

    /** Retries are expected by design — the second one must not re-verify. */
    public function test_a_retried_notification_is_recorded_once(): void
    {
        $this->fakeStatus('success');

        $this->postJson(route('webhooks.paystation'), $this->ipn())->assertStatus(200);
        $this->postJson(route('webhooks.paystation'), $this->ipn())->assertStatus(200);

        $this->assertSame(1, $this->payment->transactions()->where('type', 'ipn')->count());
    }
}
