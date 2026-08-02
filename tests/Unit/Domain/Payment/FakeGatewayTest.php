<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\Gateways\Contracts\GatewayIntentResult;
use App\Domain\Payment\Gateways\Contracts\GatewayRefundResult;
use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\Contracts\GatewayWebhookResult;
use App\Domain\Payment\Gateways\FakeGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FakeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;

        $attendee = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create();
        $registration = Registration::factory()->for($ticketType)->for($attendee)->create();

        $this->payment = Payment::factory()->for($registration)->for($attendee)->create([
            'status' => 'pending',
            'method' => 'bkash',
            'payer_msisdn' => '8801700000001',
        ]);
    }

    public function test_create_intent_returns_redirect_url_and_reference(): void
    {
        $result = $this->gateway->createIntent($this->payment, 'https://example.test/callback');

        $this->assertInstanceOf(GatewayIntentResult::class, $result);
        $this->assertNotEmpty($result->gatewayReference);
        $this->assertNotEmpty($result->redirectUrl);
        $this->assertStringContainsString('https://fake-gateway.test/pay/', $result->redirectUrl);
        $this->assertNotEmpty($result->rawResponse);
    }

    public function test_create_intent_stores_session_in_cache(): void
    {
        $result = $this->gateway->createIntent($this->payment, 'https://example.test/callback');

        $session = Cache::get("fake_gateway:session:{$result->gatewayReference}");

        $this->assertIsArray($session);
        $this->assertArrayHasKey('status', $session);
        $this->assertArrayHasKey('amount_paisa', $session);
        $this->assertArrayHasKey('gateway_transaction_id', $session);
        $this->assertEquals($this->payment->amount_due_paisa, $session['amount_paisa']);
    }

    public function test_create_intent_fails_when_payer_msisdn_matches_failure_trigger(): void
    {
        $this->payment->payer_msisdn = FakeGateway::FAILURE_TRIGGER_MSISDN;
        $this->payment->save();

        $result = $this->gateway->createIntent($this->payment, 'https://example.test/callback');

        $session = Cache::get("fake_gateway:session:{$result->gatewayReference}");

        $this->assertEquals(GatewayVerificationResult::STATUS_FAILED, $session['status']);
    }

    public function test_verify_returns_succeeded_when_session_status_is_succeeded(): void
    {
        $result = $this->gateway->createIntent($this->payment, 'https://example.test/callback');

        $this->payment->gateway_reference = $result->gatewayReference;
        $this->payment->save();

        $verification = $this->gateway->verify($this->payment);

        $this->assertInstanceOf(GatewayVerificationResult::class, $verification);
        $this->assertEquals(GatewayVerificationResult::STATUS_SUCCEEDED, $verification->status);
        $this->assertEquals($this->payment->amount_due_paisa, $verification->settledAmountPaisa);
        $this->assertNotEmpty($verification->gatewayTransactionId);
    }

    public function test_verify_returns_failed_when_session_status_is_failed(): void
    {
        $this->payment->payer_msisdn = FakeGateway::FAILURE_TRIGGER_MSISDN;
        $this->payment->save();

        $result = $this->gateway->createIntent($this->payment, 'https://example.test/callback');

        $this->payment->gateway_reference = $result->gatewayReference;
        $this->payment->save();

        $verification = $this->gateway->verify($this->payment);

        $this->assertEquals(GatewayVerificationResult::STATUS_FAILED, $verification->status);
        $this->assertNull($verification->settledAmountPaisa);
    }

    public function test_verify_returns_pending_when_session_not_found(): void
    {
        $this->payment->gateway_reference = 'NONEXISTENT';
        $this->payment->save();

        $verification = $this->gateway->verify($this->payment);

        $this->assertEquals(GatewayVerificationResult::STATUS_PENDING, $verification->status);
        $this->assertNull($verification->settledAmountPaisa);
        $this->assertNull($verification->gatewayTransactionId);
    }

    public function test_refund_returns_succeeded_with_reference(): void
    {
        $result = $this->gateway->refund($this->payment, 50000, 'duplicate_charge');

        $this->assertInstanceOf(GatewayRefundResult::class, $result);
        $this->assertEquals(GatewayRefundResult::STATUS_SUCCEEDED, $result->status);
        $this->assertNotEmpty($result->gatewayReference);
        $this->assertStringContainsString('FAKE-REFUND-', $result->gatewayReference);
        $this->assertNotEmpty($result->rawResponse);
    }

    public function test_parse_webhook_returns_valid_signature_for_correct_payload(): void
    {
        $result = $this->gateway->createIntent($this->payment, 'https://example.test/callback');

        $payload = [
            'gateway_reference' => $result->gatewayReference,
            'status' => 'succeeded',
            'signature' => hash_hmac('sha256', "{$result->gatewayReference}|succeeded", config('services.fake_gateway.webhook_secret')),
        ];

        $request = $this->createJsonRequest($payload);

        $webhook = $this->gateway->parseWebhook($request);

        $this->assertInstanceOf(GatewayWebhookResult::class, $webhook);
        $this->assertEquals($result->gatewayReference, $webhook->gatewayReference);
        $this->assertEquals('succeeded', $webhook->status);
        $this->assertTrue($webhook->signatureValid);
    }

    public function test_parse_webhook_returns_invalid_signature_for_tampered_payload(): void
    {
        $result = $this->gateway->createIntent($this->payment, 'https://example.test/callback');

        $payload = [
            'gateway_reference' => $result->gatewayReference,
            'status' => 'failed',
            'signature' => hash_hmac('sha256', "{$result->gatewayReference}|succeeded", config('services.fake_gateway.webhook_secret')),
        ];

        $request = $this->createJsonRequest($payload);

        $webhook = $this->gateway->parseWebhook($request);

        $this->assertFalse($webhook->signatureValid);
    }

    public function test_parse_webhook_returns_invalid_signature_for_missing_signature(): void
    {
        $result = $this->gateway->createIntent($this->payment, 'https://example.test/callback');

        $payload = [
            'gateway_reference' => $result->gatewayReference,
            'status' => 'succeeded',
        ];

        $request = $this->createJsonRequest($payload);

        $webhook = $this->gateway->parseWebhook($request);

        $this->assertFalse($webhook->signatureValid);
    }

    private function createJsonRequest(array $data): Request
    {
        return Request::create('/', 'POST', [], [], [], [], json_encode($data));
    }
}
