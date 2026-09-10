<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Actions\ProcessGatewayWebhook;
use App\Domain\Payment\Actions\RefundPayment;
use App\Domain\Payment\Gateways\Contracts\GatewayIntentResult;
use App\Domain\Payment\Gateways\Contracts\GatewayRefundResult;
use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\Contracts\GatewayWebhookResult;
use App\Domain\Payment\Gateways\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Models\Payment;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayStation adapter (paystation.com.bd/documentation).
 *
 * PayStation is an aggregator: one hosted checkout fronts bKash, Nagad,
 * Rocket, Upay, cards and internet banking, so this single adapter
 * replaces what would otherwise be four merchant onboardings. Sandbox
 * credentials are published in their docs, which is why — unlike the
 * bkash/nagad/rocket adapters still on {@see FakeGateway} — this one was
 * exercised against the live sandbox while it was written.
 *
 * Three endpoints, all of them `POST`:
 *
 *   /initiate-payment    credentials in the **body**, returns `payment_url`
 *   /transaction-status  `merchantId` in the **header**, keyed by invoice_number
 *   /v2/transaction-status  keyed by trxId — deliberately unused, see verify()
 *
 * `gateway_reference` is our own `payment_number`, sent as PayStation's
 * `invoice_number`. It is the only key that works on every path: we hold
 * it before the payer ever reaches the gateway, the status API accepts it,
 * and the IPN carries it back. A `trx_id` exists only after a payment
 * completes, so it can never be the lookup key for "did this settle?".
 *
 * Response quirks confirmed against the live sandbox on 2026-09-10, none
 * of which are in the published docs:
 *
 *  - `status_code` is a **string** (`"200"`, `"1008"`), not an integer.
 *  - `/initiate-payment` answers `Content-Type: text/html` while returning
 *    a JSON body. Nothing here may branch on content type; `Response::json()`
 *    decodes on the body alone. (`/transaction-status` does send JSON.)
 *  - Every error is HTTP **200** with a failure `status_code` in the body.
 *  - `1001 Invalid Credential.` exists and is undocumented.
 *  - `/v2/transaction-status` returns a **500 HTML error page** for a
 *    trxId it does not know, rather than a `2001` envelope.
 */
class PayStationClient implements PaymentGatewayInterface
{
    /** Request succeeded. Note: a *string* in every response body. */
    private const string CODE_OK = '200';

    /** `invoice_number` has already been used. Invoices are single-use at PayStation. */
    private const string CODE_DUPLICATE_INVOICE = '1008';

    /** Undocumented, but real: wrong merchantId/password on /initiate-payment. */
    private const string CODE_INVALID_CREDENTIAL = '1001';

    /**
     * "Transaction not found in system" — and, critically, **also what a
     * wrong `merchantId` header returns**. Confirmed live: a valid invoice
     * queried with a bogus merchant id is answered with exactly this,
     * byte for byte, so 2001 can never be read as "this payment failed".
     * See {@see verify()}.
     */
    private const string CODE_NOT_FOUND = '2001';

    /**
     * Settled. **Both spellings are required.**
     *
     * PayStation's documentation lists the `trx_status` values as
     * `processing | success | failed | refund`, and its IPN section says
     * the notification carries `"Success"`. The live sandbox returns
     * neither: a completed card payment reports **`successful`**
     * (confirmed 2026-09-10 against a real transaction —
     * `trx_id c5ab1d6b-ffb0-43c8-b7a8-3fc075b55503`, MASTERCARD, ৳2,500).
     *
     * Every documented spelling is kept alongside the observed one: the
     * vendor runs per-reseller deployments and the IPN and status API
     * already disagree with each other on casing, so narrowing this to
     * the one word seen here would be trading a known bug for a likely
     * one. Comparison is lowercased before it reaches this list.
     *
     * @var list<string>
     */
    private const array TRX_SUCCEEDED = ['successful', 'success'];

    /**
     * Terminal failure. `refund` means it was paid and the money has since
     * gone back — terminal, and emphatically not a success.
     *
     * Only spellings that unambiguously mean "this is over and no money is
     * held" belong here. Anything unrecognised deliberately stays pending
     * instead (see {@see verify()}): that is what kept a genuinely paid
     * transaction recoverable when `successful` turned out to be missing
     * from this class's vocabulary, and the same protection has to survive
     * the failure words being wrong too.
     *
     * @var list<string>
     */
    private const array TRX_FAILED = ['failed', 'failure', 'unsuccessful', 'cancelled', 'canceled', 'refund', 'refunded'];

    public function createIntent(Payment $payment, string $callbackUrl): GatewayIntentResult
    {
        $attendee = $payment->attendee;

        if ($attendee === null) {
            throw new RuntimeException("Cannot create a PayStation session for payment {$payment->payment_number}: no attendee on record.");
        }

        $invoiceNumber = $payment->payment_number;

        $response = $this->decode(Http::asForm()
            ->acceptJson()
            ->post($this->url('/initiate-payment'), [
                'merchantId' => $this->merchantId(),
                'password' => $this->password(),
                'invoice_number' => $invoiceNumber,
                'currency' => $payment->currency,
                'payment_amount' => $this->toDecimalBdt($payment->amount_due_paisa),

                // 0 = the merchant absorbs the gateway charge, which is
                // what keeps the settled amount equal to `amount_due_paisa`.
                // Setting this to 1 makes the payer pay our amount *plus*
                // the charge; verify() compares against `request_amount`
                // precisely so that stays workable, but read the config
                // comment before flipping it.
                'pay_with_charge' => $this->payWithCharge(),

                'reference' => $payment->registration->registration_number ?? $invoiceNumber,
                'cust_name' => $attendee->full_name,
                'cust_phone' => $attendee->mobile,
                'cust_email' => $attendee->email ?? 'no-reply@'.$this->emailFallbackDomain(),
                'cust_address' => $attendee->current_address ?? 'N/A',
                'callback_url' => $this->returnUrl($payment),
                'checkout_items' => 'Event ticket',

                // Carried through the gateway and echoed back on the status
                // API, so a transaction can be tied to a registration from
                // PayStation's own records during a dispute.
                'opt_a' => $payment->registration?->ulid,
            ])->throw());

        $statusCode = $this->statusCode($response);

        if ($statusCode !== self::CODE_OK || empty($response['payment_url'])) {
            throw new RuntimeException($this->initiationFailureMessage($statusCode, $response, $invoiceNumber));
        }

        return new GatewayIntentResult(
            gatewayReference: $invoiceNumber,
            redirectUrl: (string) $response['payment_url'],
            rawResponse: $response,
        );
    }

    /**
     * Status lookup by `invoice_number` against v1 `/transaction-status`.
     *
     * v2 is deliberately not used even though it returns a slightly richer
     * body: it is keyed by `trxId`, which only exists once a payment has
     * already completed, so it cannot answer the question this method is
     * asked ("did this settle?") for the pending payments that need it
     * most. It also answers an unknown trxId with a 500 HTML error page
     * rather than an error envelope, which would surface here as an
     * exception on the ordinary "not paid yet" path.
     */
    public function verify(Payment $payment): GatewayVerificationResult
    {
        $invoiceNumber = $payment->gateway_reference ?? $payment->payment_number;

        $response = $this->decode(Http::asForm()
            ->withHeaders(['merchantId' => $this->merchantId()])
            ->acceptJson()
            ->post($this->url('/transaction-status'), ['invoice_number' => $invoiceNumber])
            ->throw());

        $statusCode = $this->statusCode($response);

        if ($statusCode !== self::CODE_OK) {
            // 2001 is *not* evidence of failure. PayStation answers a
            // wrong merchantId with the same "Transaction not found"
            // envelope as a genuinely unknown invoice, so treating it as
            // terminal would let one misconfigured credential fail every
            // pending payment in the system and release its capacity —
            // the same shape of bug REVE's `REJECTD` caused in the SMS
            // poller (CLAUDE.md, 2026-08-22). Unknown means unknown.
            return new GatewayVerificationResult(
                status: GatewayVerificationResult::STATUS_PENDING,
                settledAmountPaisa: null,
                gatewayTransactionId: null,
                rawResponse: $response + ['reason' => $statusCode === self::CODE_NOT_FOUND
                    ? 'not_found_or_credentials_rejected'
                    : 'unexpected_status_code'],
            );
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $trxStatus = strtolower(trim((string) ($data['trx_status'] ?? '')));
        $trxId = trim((string) ($data['trx_id'] ?? ''));

        $status = match (true) {
            in_array($trxStatus, self::TRX_SUCCEEDED, true) => GatewayVerificationResult::STATUS_SUCCEEDED,
            in_array($trxStatus, self::TRX_FAILED, true) => GatewayVerificationResult::STATUS_FAILED,

            // Anything else stays pending on purpose — `processing`
            // (PayStation's word for "the payer has a checkout session open
            // and has not finished"), or a word this class has never seen.
            // There is deliberately no `pending` list to keep in step: the
            // safe answer is the default, so an unknown word cannot be
            // mishandled by being left out of one. A status we do not recognise
            // is not a licence to fail a real payment, and the expiry
            // sweeper will re-check and eventually release the capacity if
            // it really was abandoned. This arm is what made the
            // `successful` / `success` discrepancy a stuck page rather than
            // a payer charged and told their payment failed.
            default => GatewayVerificationResult::STATUS_PENDING,
        };

        return new GatewayVerificationResult(
            status: $status,
            // Only a settled payment has an amount worth comparing; leaving
            // it null elsewhere keeps VerifyPayment's equality check from
            // reading a refunded or abandoned figure as the sum received.
            settledAmountPaisa: $status === GatewayVerificationResult::STATUS_SUCCEEDED
                ? $this->settledAmountPaisa($data)
                : null,
            gatewayTransactionId: $trxId !== '' ? $trxId : null,
            rawResponse: $response,
        );
    }

    /**
     * PayStation publishes no refund endpoint — `refund` appears only as a
     * value of `trx_status`, i.e. something that can be reported, not
     * caused. Refunds are performed by a human in the merchant panel, so
     * {@see RefundPayment} never calls this;
     * it takes the out-of-band acknowledgement path instead. Kept
     * fail-closed rather than deleted so that a future caller reaching for
     * it cannot accidentally book a refund that never happened.
     */
    public function refund(Payment $payment, int $amountPaisa, string $reason): GatewayRefundResult
    {
        return new GatewayRefundResult(
            status: GatewayRefundResult::STATUS_FAILED,
            gatewayReference: null,
            rawResponse: ['reason' => 'paystation_publishes_no_refund_api'],
        );
    }

    public function supportsGatewayRefund(): bool
    {
        return false;
    }

    /**
     * PayStation's IPN is an unauthenticated JSON POST — their docs state
     * "Auth: None" and define no signature, HMAC or shared secret. There
     * is therefore nothing to verify, which is reported honestly as
     * `unsigned` rather than as a failed check.
     *
     * That is safe here only because of what a webhook is allowed to do:
     * it never settles a payment: {@see ProcessGatewayWebhook}
     * uses it solely as a prompt to call {@see verify()}, which re-reads
     * status *and* amount from PayStation over a channel the caller cannot
     * influence. A forged IPN naming a real invoice therefore achieves
     * exactly one thing — an outbound status lookup — which is why the
     * route is rate-limited and why the (empty by default) source-IP
     * allowlist hangs off it.
     *
     * The body's own `trx_amount` is deliberately ignored for the same
     * reason: PayStation's own integration checklist tells merchants to
     * compare it against the expected amount, but a value the caller
     * supplies cannot check itself. verify() does that comparison against
     * the gateway's answer instead, which is strictly stronger.
     */
    public function parseWebhook(Request $request): GatewayWebhookResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all() ?: ($request->request->all() + $request->query->all());

        $invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
        $trxId = trim((string) ($payload['trx_id'] ?? ''));
        $trxStatus = strtolower(trim((string) ($payload['trx_status'] ?? '')));

        return new GatewayWebhookResult(
            gatewayReference: $invoiceNumber,
            gatewayTransactionId: $trxId !== '' ? $trxId : null,
            // The IPN fires only for successes, but the value is still read
            // rather than assumed — and it arrives capitalised ("Success")
            // where the status API returns it lowercase, hence strtolower.
            status: in_array($trxStatus, self::TRX_SUCCEEDED, true)
                ? GatewayVerificationResult::STATUS_SUCCEEDED
                : GatewayVerificationResult::STATUS_PENDING,
            signatureValid: false,
            rawPayload: $payload,
            authenticity: GatewayWebhookResult::AUTH_UNSIGNED,
        );
    }

    /**
     * `request_amount` is what we asked PayStation to collect;
     * `payment_amount` is what the payer was charged, which includes the
     * gateway fee when `pay_with_charge=1`. Preferring the former keeps
     * VerifyPayment's exact-equality check against `amount_due_paisa`
     * correct under either charge setting.
     *
     * The published v1 field list omits `request_amount` entirely — it is
     * in fact returned, confirmed live — so the fallback is kept for a
     * deployment where it is not.
     *
     * @param  array<string, mixed>  $data
     */
    private function settledAmountPaisa(array $data): ?int
    {
        foreach (['request_amount', 'payment_amount', 'trx_amount'] as $field) {
            $value = $data[$field] ?? null;

            if (is_numeric($value)) {
                return (int) round(((float) $value) * 100);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function initiationFailureMessage(string $statusCode, array $response, string $invoiceNumber): string
    {
        $message = trim((string) ($response['message'] ?? '')) ?: 'no message';

        return match ($statusCode) {
            self::CODE_DUPLICATE_INVOICE => "PayStation has already seen invoice [{$invoiceNumber}]. "
                .'An invoice number is single-use there, so a payment row can only be initiated once; '
                .'re-check its existing session or verify it rather than creating a second one.',
            self::CODE_INVALID_CREDENTIAL => 'PayStation rejected the merchant credentials. Check '
                .'PAYSTATION_MERCHANT_ID / PAYSTATION_MERCHANT_PASSWORD against the environment named by '
                .'PAYSTATION_BASE_URL — sandbox credentials are not valid on the live host, or the reverse.',
            default => "PayStation session initiation failed [{$statusCode}]: {$message}",
        };
    }

    /**
     * Every response body is JSON, but not every response says so — see
     * the class docblock. Anything that fails to decode into an array is
     * normalised to an empty one so callers get a clean "unexpected
     * status code" rather than a type error.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function statusCode(array $response): string
    {
        return trim((string) ($response['status_code'] ?? ''));
    }

    private function toDecimalBdt(int $paisa): string
    {
        return number_format($paisa / 100, 2, '.', '');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.paystation.base_url'), '/').$path;
    }

    private function merchantId(): string
    {
        return (string) config('services.paystation.merchant_id');
    }

    private function password(): string
    {
        return (string) config('services.paystation.password');
    }

    private function payWithCharge(): int
    {
        return (int) config('services.paystation.pay_with_charge', 0);
    }

    private function emailFallbackDomain(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'example.com';
    }

    /**
     * PayStation takes a single `callback_url` — there is no separate
     * success/fail/cancel leg. The payment ULID travels in the **path**
     * rather than a query string, so nothing depends on the gateway
     * preserving our query parameters, and the redirect target is derived
     * entirely from server-side config: there is no caller-supplied `next`
     * to turn this into an open redirect.
     */
    private function returnUrl(Payment $payment): string
    {
        return route('api.v1.public.payments.paystation.return', ['payment' => $payment->ulid]);
    }
}
