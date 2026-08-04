<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\Gateways\Contracts\GatewayRefundResult;
use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\SslCommerzClient;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SslCommerzClientTest extends TestCase
{
    use RefreshDatabase;

    private SslCommerzClient $gateway;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.sslcommerz.store_password' => 'sandbox-store-password']);

        $this->gateway = new SslCommerzClient;

        $attendee = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create();
        $registration = Registration::factory()->for($ticketType)->for($attendee)->create();

        $this->payment = Payment::factory()->for($registration)->for($attendee)->create([
            'status' => 'initiated',
            'method' => 'sslcommerz',
            'payment_number' => 'PAY-SSLTEST01',
            'amount_due_paisa' => 50000,
        ]);
    }

    public function test_create_intent_returns_the_gateway_hosted_page_url(): void
    {
        Http::fake([
            'sandbox-gw.sslcommerz.com/*' => Http::response([
                'status' => 'SUCCESS',
                'sessionkey' => 'sesh123',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/testcde',
            ]),
        ]);

        $result = $this->gateway->createIntent($this->payment, 'https://frontend.test/registrations/abc');

        $this->assertEquals('PAY-SSLTEST01', $result->gatewayReference);
        $this->assertEquals('https://sandbox.sslcommerz.com/EasyCheckOut/testcde', $result->redirectUrl);

        Http::assertSent(function (ClientRequest $request) {
            return $request['total_amount'] === '500.00'
                && $request['tran_id'] === 'PAY-SSLTEST01'
                && str_contains((string) $request['success_url'], 'return/success')
                && str_contains((string) $request['fail_url'], 'return/fail')
                && str_contains((string) $request['cancel_url'], 'return/cancel');
        });
    }

    public function test_create_intent_throws_when_gateway_reports_failure(): void
    {
        Http::fake([
            'sandbox-gw.sslcommerz.com/*' => Http::response([
                'status' => 'FAILED',
                'failedreason' => 'Invalid store credential',
            ]),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway->createIntent($this->payment, 'https://frontend.test/registrations/abc');
    }

    public function test_create_intent_rejects_amounts_below_the_ten_taka_minimum(): void
    {
        $this->payment->amount_due_paisa = 500;

        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->gateway->createIntent($this->payment, 'https://frontend.test/registrations/abc');

        Http::assertNothingSent();
    }

    public function test_verify_returns_pending_when_no_ipn_and_no_transaction_found(): void
    {
        Http::fake([
            '*/validationserverAPI.php*' => Http::response(['no_of_trans_found' => 0]),
        ]);

        $result = $this->gateway->verify($this->payment);

        $this->assertEquals(GatewayVerificationResult::STATUS_PENDING, $result->status);
    }

    public function test_verify_falls_back_to_tran_id_lookup_and_recovers_a_missing_ipn(): void
    {
        Http::fake([
            '*/validationserverAPI.php*' => Http::response([
                'no_of_trans_found' => 1,
                'element' => [
                    ['status' => 'VALID', 'amount' => '500.00', 'bank_tran_id' => 'BANKTXN1'],
                ],
            ]),
        ]);

        $result = $this->gateway->verify($this->payment);

        $this->assertEquals(GatewayVerificationResult::STATUS_SUCCEEDED, $result->status);
        $this->assertEquals(50000, $result->settledAmountPaisa);
        $this->assertEquals('BANKTXN1', $result->gatewayTransactionId);

        Http::assertSent(fn (ClientRequest $request) => ($request['tran_id'] ?? null) === 'PAY-SSLTEST01');
    }

    public function test_verify_uses_val_id_from_a_prior_valid_ipn(): void
    {
        $this->payment->transactions()->create([
            'type' => 'ipn',
            'direction' => 'inbound',
            'gateway' => 'sslcommerz',
            'status' => 'success',
            'signature_valid' => true,
            'request_payload' => ['tran_id' => 'PAY-SSLTEST01', 'val_id' => 'VAL123'],
        ]);

        Http::fake([
            '*/validationserverAPI.php*' => Http::response([
                'status' => 'VALIDATED',
                'amount' => '500.00',
                'bank_tran_id' => 'BANKTXN2',
            ]),
        ]);

        $result = $this->gateway->verify($this->payment);

        $this->assertEquals(GatewayVerificationResult::STATUS_SUCCEEDED, $result->status);

        Http::assertSent(fn (ClientRequest $request) => ($request['val_id'] ?? null) === 'VAL123');
    }

    public function test_verify_returns_failed_for_a_terminal_gateway_status(): void
    {
        $this->payment->transactions()->create([
            'type' => 'ipn',
            'direction' => 'inbound',
            'gateway' => 'sslcommerz',
            'status' => 'success',
            'signature_valid' => true,
            'request_payload' => ['tran_id' => 'PAY-SSLTEST01', 'val_id' => 'VAL123'],
        ]);

        Http::fake([
            '*/validationserverAPI.php*' => Http::response(['status' => 'INVALID_TRANSACTION']),
        ]);

        $result = $this->gateway->verify($this->payment);

        $this->assertEquals(GatewayVerificationResult::STATUS_FAILED, $result->status);
    }

    public function test_parse_webhook_validates_a_correctly_signed_payload(): void
    {
        $payload = [
            'tran_id' => 'PAY-SSLTEST01',
            'val_id' => 'VAL123',
            'amount' => '500.00',
            'status' => 'VALID',
            'bank_tran_id' => 'BANKTXN1',
        ];

        $payload['verify_key'] = 'tran_id,amount,status';
        $payload['verify_sign'] = $this->sign($payload, 'tran_id,amount,status', 'sandbox-store-password');

        $request = Request::create('/', 'POST', $payload);

        $result = $this->gateway->parseWebhook($request);

        $this->assertEquals('PAY-SSLTEST01', $result->gatewayReference);
        $this->assertEquals(GatewayVerificationResult::STATUS_SUCCEEDED, $result->status);
        $this->assertTrue($result->signatureValid);
    }

    public function test_parse_webhook_rejects_a_tampered_payload(): void
    {
        $payload = [
            'tran_id' => 'PAY-SSLTEST01',
            'amount' => '500.00',
            'status' => 'VALID',
        ];

        $payload['verify_key'] = 'tran_id,amount,status';
        $payload['verify_sign'] = $this->sign($payload, 'tran_id,amount,status', 'sandbox-store-password');

        // Tamper the amount after signing.
        $payload['amount'] = '1.00';

        $request = Request::create('/', 'POST', $payload);

        $result = $this->gateway->parseWebhook($request);

        $this->assertFalse($result->signatureValid);
    }

    public function test_refund_requires_a_bank_transaction_id_on_record(): void
    {
        $this->payment->gateway_transaction_id = null;
        $this->payment->save();

        $this->expectException(RuntimeException::class);

        $this->gateway->refund($this->payment, 50000, 'attendee requested');
    }

    public function test_refund_returns_succeeded_on_a_success_response(): void
    {
        $this->payment->gateway_transaction_id = 'BANKTXN1';
        $this->payment->save();

        Http::fake([
            '*/merchantTransIDvalidationAPI.php*' => Http::response(['status' => 'success', 'refund_ref_id' => 'RF123']),
        ]);

        $result = $this->gateway->refund($this->payment, 50000, 'attendee requested');

        $this->assertEquals(GatewayRefundResult::STATUS_SUCCEEDED, $result->status);
        $this->assertEquals('RF123', $result->gatewayReference);
    }

    public function test_refund_returns_failed_on_a_failure_response(): void
    {
        $this->payment->gateway_transaction_id = 'BANKTXN1';
        $this->payment->save();

        Http::fake([
            '*/merchantTransIDvalidationAPI.php*' => Http::response(['status' => 'failed']),
        ]);

        $result = $this->gateway->refund($this->payment, 50000, 'attendee requested');

        $this->assertEquals(GatewayRefundResult::STATUS_FAILED, $result->status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload, string $verifyKey, string $storePassword): string
    {
        $pairs = [];
        foreach (explode(',', $verifyKey) as $field) {
            $pairs[] = $field.'='.(string) ($payload[$field] ?? '');
        }

        $pairs[] = 'store_passwd='.md5($storePassword);

        return md5(implode('&', $pairs));
    }
}
