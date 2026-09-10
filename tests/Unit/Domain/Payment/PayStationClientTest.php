<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\Contracts\GatewayWebhookResult;
use App\Domain\Payment\Gateways\PayStationClient;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Every fixture below is a body copied verbatim from a live call to
 * https://sandbox.paystation.com.bd on 2026-09-10 using the sandbox
 * credentials PayStation publishes in its documentation — not a body
 * inferred from the docs. That distinction is the point: three of these
 * behaviours contradict the published documentation, and two of them
 * would be money bugs if guessed at.
 */
class PayStationClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A stale fake pattern must fail loudly rather than quietly
        // reaching the real sandbox — the trap SslCommerzClientTest fell
        // into when its host changed (CLAUDE.md, 2026-08-14).
        Http::preventStrayRequests();

        config([
            'services.paystation.merchant_id' => '104-1653730183',
            'services.paystation.password' => 'gamecoderstorepass',
            'services.paystation.base_url' => 'https://sandbox.paystation.com.bd',
            'services.paystation.pay_with_charge' => 0,
        ]);
    }

    private function client(): PayStationClient
    {
        return app(PayStationClient::class);
    }

    private function payment(array $attributes = []): Payment
    {
        $attendee = Attendee::factory()->create();

        return Payment::factory()->create(array_merge([
            'attendee_id' => $attendee->id,
            'method' => 'paystation',
            'channel' => 'online',
            'status' => 'pending',
            'amount_due_paisa' => 250000,
            'currency' => 'BDT',
        ], $attributes));
    }

    public function test_create_intent_returns_the_hosted_checkout_url(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/initiate-payment' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'message' => 'Payment Link Created Successfully.',
                'payment_amount' => '2500',
                'invoice_number' => 'PAY-ABCD1234',
                'payment_url' => 'https://sandbox.paystation.com.bd/checkout/12117890172825060/P1OzOEAs9K',
            ]),
        ]);

        $payment = $this->payment(['payment_number' => 'PAY-ABCD1234']);

        $result = $this->client()->createIntent($payment, 'https://frontend.test/registrations/x');

        $this->assertSame('https://sandbox.paystation.com.bd/checkout/12117890172825060/P1OzOEAs9K', $result->redirectUrl);

        // Our own payment_number is the gateway reference: it is the only
        // key we hold before the payer reaches the gateway, and the only
        // one both the status API and the IPN accept.
        $this->assertSame('PAY-ABCD1234', $result->gatewayReference);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['merchantId'] === '104-1653730183'
                && $body['password'] === 'gamecoderstorepass'
                && $body['invoice_number'] === 'PAY-ABCD1234'
                // Paisa are converted to decimal BDT inside the adapter and
                // nowhere else — no decimal amount may leak past it.
                && $body['payment_amount'] === '2500.00'
                && $body['pay_with_charge'] === 0
                && str_contains((string) $body['callback_url'], '/payments/paystation/return/');
        });
    }

    /**
     * PayStation answers every failure with HTTP 200 and a failure
     * `status_code` in the body, so nothing may key off the HTTP status.
     */
    public function test_create_intent_reports_a_duplicate_invoice_in_the_operators_own_terms(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/initiate-payment' => Http::response([
                'status_code' => '1008',
                'status' => 'failed',
                'message' => 'Duplicate invoice number.',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already seen invoice \[PAY-DUPE0001\]/');

        $this->client()->createIntent($this->payment(['payment_number' => 'PAY-DUPE0001']), 'https://frontend.test/r');
    }

    /**
     * 1001 is undocumented — PayStation's published error table lists only
     * 200, 1008 and 2001 — but it is what a wrong password really returns,
     * and it is the single most likely misconfiguration (sandbox
     * credentials pointed at the live host, or the reverse).
     */
    public function test_create_intent_names_the_credential_mismatch_it_actually_is(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/initiate-payment' => Http::response([
                'status_code' => '1001',
                'status' => 'failed',
                'message' => 'Invalid Credential.',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/PAYSTATION_MERCHANT_ID/');

        $this->client()->createIntent($this->payment(), 'https://frontend.test/r');
    }

    /**
     * The regression that matters most in this file.
     *
     * This body is copied verbatim from the first real completed payment
     * (2026-09-10, MASTERCARD, ৳2,500). `trx_status` came back as
     * **`successful`** — a word that appears nowhere in PayStation's
     * documentation, which lists `processing | success | failed | refund`
     * and whose IPN section says `"Success"`. Until this was observed, a
     * genuinely paid transaction verified as *pending* for ever: the payer
     * was charged and the return page span on "confirming your payment".
     *
     * Both spellings are asserted, in their own tests, so neither can be
     * dropped by someone tidying up to match the docs.
     */
    public function test_verify_settles_the_successful_spelling_the_gateway_really_returns(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'message' => 'Transaction found',
                'data' => [
                    'invoice_number' => 'PAY-USOJXESV',
                    'trx_status' => 'successful',
                    'trx_id' => 'c5ab1d6b-ffb0-43c8-b7a8-3fc075b55503',
                    'request_amount' => '2500.00',
                    'payment_amount' => '2500.00',
                    'order_date_time' => '2026-09-10 14:00:31',
                    'payment_method' => 'MASTERCARD',
                    'payer_mobile_no' => '512345xxxxxx0008',
                    'reference' => 'REG-NRPC2AMC',
                ],
            ]),
        ]);

        $result = $this->client()->verify($this->payment(['gateway_reference' => 'PAY-USOJXESV']));

        $this->assertSame(GatewayVerificationResult::STATUS_SUCCEEDED, $result->status);
        $this->assertSame(250000, $result->settledAmountPaisa);
        $this->assertSame('c5ab1d6b-ffb0-43c8-b7a8-3fc075b55503', $result->gatewayTransactionId);
    }

    /** The documented spelling has to keep working too — see above. */
    public function test_verify_settles_a_successful_transaction(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'message' => 'Transaction found',
                'data' => [
                    'invoice_number' => 'PAY-ABCD1234',
                    'trx_status' => 'success',
                    'trx_id' => 'CG20D8AYB4',
                    'request_amount' => '2500.00',
                    'payment_amount' => '2500.00',
                    'order_date_time' => '2026-09-10 13:03:21',
                    'payer_mobile_no' => '01811361428',
                    'payment_method' => 'bKash',
                ],
            ]),
        ]);

        $result = $this->client()->verify($this->payment(['gateway_reference' => 'PAY-ABCD1234']));

        $this->assertSame(GatewayVerificationResult::STATUS_SUCCEEDED, $result->status);
        $this->assertSame(250000, $result->settledAmountPaisa);
        $this->assertSame('CG20D8AYB4', $result->gatewayTransactionId);

        Http::assertSent(fn ($request) => $request->header('merchantId') === ['104-1653730183']
            && $request->data()['invoice_number'] === 'PAY-ABCD1234');
    }

    /**
     * `request_amount` is what we asked PayStation to collect;
     * `payment_amount` is what the payer was charged, which includes the
     * gateway fee under `pay_with_charge=1`. Comparing the latter against
     * `amount_due_paisa` would flag every such payment as an amount
     * mismatch and park it for human review.
     */
    public function test_verify_prefers_the_requested_amount_over_the_amount_charged(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'data' => [
                    'trx_status' => 'success',
                    'trx_id' => 'CG20D8AYB4',
                    'request_amount' => '2500.00',
                    'payment_amount' => '2557.50',
                ],
            ]),
        ]);

        $this->assertSame(250000, $this->client()->verify($this->payment())->settledAmountPaisa);
    }

    public function test_verify_reports_a_checkout_in_progress_as_pending(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'data' => ['trx_status' => 'processing', 'trx_id' => '', 'request_amount' => '2500.00'],
            ]),
        ]);

        $result = $this->client()->verify($this->payment());

        $this->assertSame(GatewayVerificationResult::STATUS_PENDING, $result->status);
        $this->assertNull($result->gatewayTransactionId);
    }

    public function test_verify_reports_a_failed_transaction_as_failed(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'data' => ['trx_status' => 'failed', 'trx_id' => ''],
            ]),
        ]);

        $this->assertSame(
            GatewayVerificationResult::STATUS_FAILED,
            $this->client()->verify($this->payment())->status,
        );
    }

    /**
     * Money that came back is not money received. `refund` is terminal and
     * must not settle the payment or confirm the registration.
     */
    public function test_verify_never_settles_a_transaction_the_gateway_says_was_refunded(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'data' => ['trx_status' => 'refund', 'trx_id' => 'CG20D8AYB4', 'request_amount' => '2500.00'],
            ]),
        ]);

        $result = $this->client()->verify($this->payment());

        $this->assertSame(GatewayVerificationResult::STATUS_FAILED, $result->status);
        $this->assertNull($result->settledAmountPaisa);
    }

    /**
     * The load-bearing one.
     *
     * Confirmed live: querying a perfectly valid invoice with a wrong
     * `merchantId` header returns exactly the same `2001 Transaction not
     * found in system` envelope as an invoice that does not exist. If 2001
     * were read as terminal, one mistyped credential would fail every
     * pending payment in the system and release all their capacity — the
     * shape of bug REVE's `REJECTD` caused in the SMS poller. Unknown must
     * stay unknown.
     */
    public function test_a_not_found_answer_is_pending_because_it_is_also_what_bad_credentials_return(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '2001',
                'status' => 'failed',
                'message' => 'Transaction not found in system',
            ], 200),
        ]);

        $result = $this->client()->verify($this->payment());

        $this->assertSame(GatewayVerificationResult::STATUS_PENDING, $result->status);
        $this->assertSame('not_found_or_credentials_rejected', $result->rawResponse['reason']);
    }

    /**
     * A status word we do not recognise is not a licence to fail a real
     * payment — and this arm is not hypothetical. It is what turned the
     * undocumented `successful` spelling into a recoverable stuck page
     * instead of a payer charged and told their payment had failed.
     */
    public function test_an_unrecognised_transaction_status_is_pending_not_failed(): void
    {
        Http::fake([
            'sandbox.paystation.com.bd/transaction-status' => Http::response([
                'status_code' => '200',
                'status' => 'success',
                'data' => ['trx_status' => 'something_new', 'trx_id' => ''],
            ]),
        ]);

        $this->assertSame(
            GatewayVerificationResult::STATUS_PENDING,
            $this->client()->verify($this->payment())->status,
        );
    }

    /**
     * PayStation's IPN carries no signature of any kind. Reporting it as
     * `signatureValid = false` would say a check failed; reporting it as
     * true would be a lie. It is neither — it is unsigned, and that is a
     * third state.
     */
    public function test_the_ipn_is_reported_as_unsigned_rather_than_valid_or_invalid(): void
    {
        $result = $this->client()->parseWebhook(Request::create(
            '/webhooks/paystation',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'invoice_number' => 'PAY-ABCD1234',
                'trx_status' => 'Success',
                'trx_id' => 'CG20D8AYB4',
                'trx_amount' => 2500,
                'payment_method' => 'Nagad',
            ], JSON_THROW_ON_ERROR),
        ));

        $this->assertSame(GatewayWebhookResult::AUTH_UNSIGNED, $result->authenticity);
        $this->assertFalse($result->signatureValid);

        // NULL in the database, not false: the column records the outcome
        // of a check that here never happened.
        $this->assertNull($result->recordedSignatureValid());

        // It may still prompt a server-to-server verify — that is all
        // acting on it ever does.
        $this->assertTrue($result->mayTriggerVerification());

        $this->assertSame('PAY-ABCD1234', $result->gatewayReference);
        $this->assertSame('CG20D8AYB4', $result->gatewayTransactionId);

        // "Success" from the IPN, "success" from the status API.
        $this->assertSame(GatewayVerificationResult::STATUS_SUCCEEDED, $result->status);
    }

    public function test_paystation_declares_it_cannot_refund(): void
    {
        $this->assertFalse($this->client()->supportsGatewayRefund());

        // And fails closed if something reaches for it anyway.
        $this->assertFalse($this->client()->refund($this->payment(), 250000, 'test')->isSucceeded());
    }
}
