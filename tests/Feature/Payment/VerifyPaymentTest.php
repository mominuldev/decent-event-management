<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use App\Jobs\IssueTicketForRegistrationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class VerifyPaymentTest extends TestCase
{
    use RefreshDatabase;

    private TicketType $ticketType;

    private Registration $registration;

    private Payment $payment;

    private VerifyPayment $verifyPayment;

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
                'amount_due_paisa' => 50000,
            ]);

        $this->verifyPayment = app(VerifyPayment::class);
    }

    public function test_verify_with_succeeded_status_moves_payment_to_succeeded_and_issues_a_ticket(): void
    {
        $gatewayReference = 'FAKE-VERIFY-'.strtoupper(bin2hex(random_bytes(10)));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'succeeded',
            'amount_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN123',
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $outcome = $this->verifyPayment->handle($this->payment);

        $this->assertEquals(VerifyPayment::OUTCOME_SUCCEEDED, $outcome);

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'succeeded',
            'amount_paid_paisa' => 50000,
            'net_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN123',
        ]);

        $this->assertDatabaseHas('ticket_types', [
            'id' => $this->ticketType->id,
            'quantity_reserved' => 0,
            'quantity_sold' => 1,
        ]);

        // D1/D2 regression: a gateway-verified payment must issue a ticket,
        // not just move the payment/registration to a paid status.
        $ticket = Ticket::where('registration_id', $this->registration->id)->first();

        $this->assertNotNull($ticket, 'Gateway-verified payment did not issue a ticket.');
        $this->assertEquals('active', $ticket->status);
        $this->assertEquals($this->registration->attendee_id, $ticket->attendee_id);

        $this->assertDatabaseHas('registrations', [
            'ulid' => $this->registration->ulid,
            'status' => 'confirmed',
        ]);
    }

    public function test_verify_does_not_issue_a_second_ticket_when_replayed_after_success(): void
    {
        $gatewayReference = 'FAKE-VERIFY-'.strtoupper(bin2hex(random_bytes(10)));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'succeeded',
            'amount_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN123',
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $this->verifyPayment->handle($this->payment);
        $this->payment->refresh();

        // A retried webhook/sweeper pass on an already-succeeded payment is
        // a no-op (short-circuited before the transaction), so it must not
        // dispatch a second ticket-issuance event.
        $this->verifyPayment->handle($this->payment);

        $this->assertEquals(1, Ticket::where('registration_id', $this->registration->id)->count());
    }

    public function test_verify_with_failed_status_moves_payment_to_failed_and_releases_capacity(): void
    {
        $gatewayReference = 'FAKE-VERIFY-'.strtoupper(bin2hex(random_bytes(10)));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'failed',
            'amount_paisa' => null,
            'gateway_transaction_id' => null,
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $outcome = $this->verifyPayment->handle($this->payment);

        $this->assertEquals(VerifyPayment::OUTCOME_FAILED, $outcome);

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

    public function test_verify_with_amount_mismatch_flags_reconciliation(): void
    {
        $gatewayReference = 'FAKE-VERIFY-'.strtoupper(bin2hex(random_bytes(10)));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'succeeded',
            'amount_paisa' => 45000,
            'gateway_transaction_id' => 'FAKETXN123',
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $outcome = $this->verifyPayment->handle($this->payment);

        $this->assertEquals(VerifyPayment::OUTCOME_AMOUNT_MISMATCH, $outcome);

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'initiated',
            'reconciliation_status' => 'amount_mismatch',
        ]);
    }

    public function test_verify_with_pending_status_returns_pending(): void
    {
        $gatewayReference = 'FAKE-VERIFY-'.strtoupper(bin2hex(random_bytes(10)));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'pending',
            'amount_paisa' => null,
            'gateway_transaction_id' => null,
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $outcome = $this->verifyPayment->handle($this->payment);

        $this->assertEquals(VerifyPayment::OUTCOME_PENDING, $outcome);

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'initiated',
        ]);
    }

    public function test_verify_is_idempotent_when_payment_already_succeeded(): void
    {
        $this->payment->update([
            'status' => 'succeeded',
            'amount_paid_paisa' => 50000,
            'net_paisa' => 50000,
        ]);

        $this->registration->update(['status' => 'paid']);

        $this->ticketType->update([
            'quantity_reserved' => 0,
            'quantity_sold' => 1,
        ]);

        $outcome = $this->verifyPayment->handle($this->payment);

        $this->assertEquals(VerifyPayment::OUTCOME_SUCCEEDED, $outcome);

        $this->assertDatabaseHas('ticket_types', [
            'id' => $this->ticketType->id,
            'quantity_reserved' => 0,
            'quantity_sold' => 1,
        ]);
    }

    /**
     * The money invariant: a payment the gateway has confirmed stays
     * confirmed, whatever happens downstream of it.
     *
     * Ticket issuance used to run synchronously off `PaymentSucceeded`,
     * which `VerifyPayment` dispatches from inside its own transaction —
     * so a throw in issuance rolled the settlement back and left the payer
     * charged at the gateway with the registration still `pending_payment`
     * and the return page polling a spinner for ever. The commonest cause
     * is the one set up here: a deployment where `qr-signing:generate-key`
     * was never run, so `QrSigner::sign()` throws on every issuance.
     *
     * Runs against the `database` queue driver rather than the suite's
     * `sync` default because that is what the deployment target uses
     * (docs/09 section 5) — and because it is the whole point of the test:
     * issuance must be a queued job, not something the settlement
     * transaction is waiting on.
     */
    public function test_a_gateway_confirmed_payment_settles_even_when_ticket_issuance_cannot_run(): void
    {
        config([
            'queue.default' => 'database',
            'services.qr_signing.active_key_id' => 'key-1',
            'services.qr_signing.active_private_key' => null,
            'services.qr_signing.private_keys' => [],
            'services.qr_signing.retired_public_keys' => [],
        ]);

        $gatewayReference = 'FAKE-VERIFY-'.strtoupper(bin2hex(random_bytes(10)));

        Cache::put("fake_gateway:session:{$gatewayReference}", [
            'status' => 'succeeded',
            'amount_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN123',
        ]);

        $this->payment->update(['gateway_reference' => $gatewayReference]);

        $outcome = $this->verifyPayment->handle($this->payment);

        $this->assertEquals(VerifyPayment::OUTCOME_SUCCEEDED, $outcome);

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'succeeded',
            'amount_paid_paisa' => 50000,
        ]);

        $this->assertDatabaseHas('registrations', [
            'ulid' => $this->registration->ulid,
            'status' => 'paid',
        ]);

        // Queued, not run inline — so nothing it does can reach back into
        // the transaction that settled the money.
        $this->assertDatabaseHas('jobs', ['queue' => 'tickets']);
        $this->assertDatabaseMissing('tickets', ['registration_id' => $this->registration->id]);
    }

    /**
     * And when that queued job does run without a signing key, it fails as
     * a job — loudly, retryably, and with the settled payment untouched.
     */
    public function test_a_failing_issuance_job_leaves_the_settled_payment_alone(): void
    {
        config([
            'services.qr_signing.active_key_id' => 'key-1',
            'services.qr_signing.active_private_key' => null,
            'services.qr_signing.private_keys' => [],
            'services.qr_signing.retired_public_keys' => [],
        ]);

        $this->payment->update([
            'status' => 'succeeded',
            'amount_paid_paisa' => 50000,
            'net_paisa' => 50000,
        ]);
        $this->registration->update(['status' => 'paid']);

        $threw = false;

        try {
            app(IssueTicketForRegistrationJob::class, ['registrationId' => $this->registration->id])
                ->handle(app(IssueTicket::class));
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('no private key', $e->getMessage());
        }

        $this->assertTrue($threw, 'Expected issuance to fail without a signing key.');

        $this->assertDatabaseHas('payments', [
            'ulid' => $this->payment->ulid,
            'status' => 'succeeded',
        ]);

        $this->assertDatabaseHas('registrations', [
            'ulid' => $this->registration->ulid,
            'status' => 'paid',
        ]);
    }
}
