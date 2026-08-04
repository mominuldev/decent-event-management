<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private TicketType $ticketType;

    private Registration $registration;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticketType = TicketType::factory()->create([
            'quantity_total' => 1000,
            'quantity_reserved' => 1,
            'quantity_sold' => 0,
        ]);

        $attendee = Attendee::factory()->create();

        $this->registration = Registration::factory()
            ->for($this->ticketType)
            ->for($attendee)
            ->create(['status' => 'pending_payment']);

        $this->payment = Payment::factory()
            ->for($this->registration)
            ->for($attendee)
            ->create([
                'status' => 'initiated',
                'method' => 'bkash',
                'gateway_reference' => null,
                'amount_due_paisa' => 50000,
            ]);
    }

    public function test_webhook_with_valid_signature_marks_payment_succeeded(): void
    {
        $gatewayReference = 'FAKE-TEST-'.strtoupper(bin2hex(random_bytes(10)));
        $signature = hash_hmac('sha256', "{$gatewayReference}|succeeded", config('services.fake_gateway.webhook_secret'));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'succeeded',
            'amount_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN123',
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $response = $this->postJson(route('webhooks.bkash'), [
            'gateway_reference' => $gatewayReference,
            'status' => 'succeeded',
            'signature' => $signature,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'succeeded',
            'amount_paid_paisa' => 50000,
            'net_paisa' => 50000,
        ]);

        // D1/D2 regression: a webhook-verified payment must carry the
        // registration all the way to `confirmed` with a ticket issued —
        // not stop at `paid`.
        $this->assertDatabaseHas('registrations', [
            'ulid' => $this->registration->ulid,
            'status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('ticket_types', [
            'id' => $this->ticketType->id,
            'quantity_reserved' => 0,
            'quantity_sold' => 1,
        ]);

        $this->assertTrue(Ticket::where('registration_id', $this->registration->id)->exists());
    }

    public function test_webhook_with_invalid_signature_is_ignored(): void
    {
        $gatewayReference = 'FAKE-TEST-'.strtoupper(bin2hex(random_bytes(10)));

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $response = $this->postJson(route('webhooks.nagad'), [
            'gateway_reference' => $gatewayReference,
            'status' => 'succeeded',
            'signature' => 'invalid',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'initiated',
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $this->payment->id,
            'type' => 'ipn',
            'status' => 'signature_invalid',
            'signature_valid' => false,
        ]);
    }

    public function test_webhook_with_amount_mismatch_flags_reconciliation(): void
    {
        $gatewayReference = 'FAKE-TEST-'.strtoupper(bin2hex(random_bytes(10)));
        $signature = hash_hmac('sha256', "{$gatewayReference}|succeeded", config('services.fake_gateway.webhook_secret'));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'succeeded',
            'amount_paisa' => 45000,
            'gateway_transaction_id' => 'FAKETXN123',
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $response = $this->postJson(route('webhooks.rocket'), [
            'gateway_reference' => $gatewayReference,
            'status' => 'succeeded',
            'signature' => $signature,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'initiated',
            'reconciliation_status' => 'amount_mismatch',
        ]);
    }

    public function test_webhook_with_failed_status_marks_payment_failed(): void
    {
        $gatewayReference = 'FAKE-TEST-'.strtoupper(bin2hex(random_bytes(10)));
        $signature = hash_hmac('sha256', "{$gatewayReference}|failed", config('services.fake_gateway.webhook_secret'));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'failed',
            'amount_paisa' => null,
            'gateway_transaction_id' => null,
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        // Not sslcommerz: as of Phase 4A that route is backed by the real
        // SslCommerzClient (which expects SSLCommerz's own IPN field
        // names, not FakeGateway's), while this test exercises the
        // generic ProcessGatewayWebhook flow against the fake stand-in.
        $response = $this->postJson(route('webhooks.bkash'), [
            'gateway_reference' => $gatewayReference,
            'status' => 'failed',
            'signature' => $signature,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('ticket_types', [
            'id' => $this->ticketType->id,
            'quantity_reserved' => 0,
            'quantity_sold' => 0,
        ]);
    }

    public function test_duplicate_webhook_is_ignored(): void
    {
        $gatewayReference = 'FAKE-TEST-'.strtoupper(bin2hex(random_bytes(10)));
        $signature = hash_hmac('sha256', "{$gatewayReference}|succeeded", config('services.fake_gateway.webhook_secret'));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'succeeded',
            'amount_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN123',
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $payload = [
            'gateway_reference' => $gatewayReference,
            'status' => 'succeeded',
            'signature' => $signature,
        ];

        $this->postJson(route('webhooks.bkash'), $payload)->assertStatus(200);

        $this->postJson(route('webhooks.bkash'), $payload)->assertStatus(200);

        $this->assertEquals(1, PaymentTransaction::where('payment_id', $this->payment->id)
            ->where('type', 'ipn')
            ->count());
    }

    public function test_webhook_with_unknown_reference_logs_warning_and_continues(): void
    {
        $response = $this->postJson(route('webhooks.bkash'), [
            'gateway_reference' => 'NONEXISTENT',
            'status' => 'succeeded',
            'signature' => hash_hmac('sha256', 'NONEXISTENT|succeeded', config('services.fake_gateway.webhook_secret')),
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('payment_transactions', [
            'type' => 'ipn',
            'gateway' => 'bkash',
        ]);
    }
}
