<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Gateways\Contracts\GatewayIntentResult;
use App\Domain\Payment\Gateways\Contracts\GatewayRefundResult;
use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\Contracts\GatewayWebhookResult;
use App\Domain\Payment\Gateways\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SSLCommerz v4 adapter (docs/08 Phase 4A; CLAUDE.md "Payments" section).
 * Sandbox credentials are self-service, so this class and its field names
 * follow the published v4 API docs (developer.sslcommerz.com/doc/v4) —
 * but it has not been exercised against a live sandbox call in this
 * session (no `SSLCOMMERZ_STORE_PASSWORD` configured here). Treat the
 * exact response field names as needing a first real-sandbox smoke test
 * before this closes Phase 4A's exit criteria.
 *
 * `tran_id` (our own `payment_number`) is used as `gateway_reference`
 * rather than SSLCommerz's `sessionkey`, since it is what the IPN and the
 * validation API both key off reliably.
 */
class SslCommerzClient implements PaymentGatewayInterface
{
    private const int MINIMUM_AMOUNT_PAISA = 1000; // SSLCommerz's 10.00 BDT minimum (CLAUDE.md "Payments" section)

    private const string STATUS_VALID = 'VALID';

    private const string STATUS_VALIDATED = 'VALIDATED';

    private const string STATUS_FAILED = 'FAILED';

    private const string STATUS_CANCELLED = 'CANCELLED';

    private const string STATUS_EXPIRED = 'EXPIRED';

    private const string STATUS_UNATTEMPTED = 'UNATTEMPTED';

    private const string STATUS_INVALID_TRANSACTION = 'INVALID_TRANSACTION';

    public function createIntent(Payment $payment, string $callbackUrl): GatewayIntentResult
    {
        if ($payment->amount_due_paisa < self::MINIMUM_AMOUNT_PAISA) {
            throw new RuntimeException('SSLCommerz requires a minimum transaction amount of 10.00 BDT.');
        }

        $tranId = $payment->payment_number;
        $attendee = $payment->attendee;

        if ($attendee === null) {
            throw new RuntimeException("Cannot create an SSLCommerz session for payment {$payment->payment_number}: no attendee on record.");
        }

        $response = Http::asForm()->post($this->sessionUrl(), [
            'store_id' => $this->storeId(),
            'store_passwd' => $this->storePassword(),
            'total_amount' => $this->toDecimalBdt($payment->amount_due_paisa),
            'currency' => $payment->currency,
            'tran_id' => $tranId,
            'success_url' => $this->returnUrl('success', $callbackUrl),
            'fail_url' => $this->returnUrl('fail', $callbackUrl),
            'cancel_url' => $this->returnUrl('cancel', $callbackUrl),
            'ipn_url' => route('webhooks.sslcommerz'),
            'cus_name' => $attendee->full_name,
            'cus_email' => $attendee->email ?? 'no-reply@example.com',
            'cus_add1' => 'N/A',
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1000',
            'cus_country' => 'Bangladesh',
            'cus_phone' => $attendee->mobile,
            'shipping_method' => 'NO',
            'product_name' => 'Event ticket',
            'product_category' => 'Event',
            'product_profile' => 'general',
            'value_a' => $payment->registration?->ulid,
        ])->throw()->json();

        if (! is_array($response) || ($response['status'] ?? null) !== 'SUCCESS' || empty($response['GatewayPageURL'])) {
            $reason = is_array($response) ? (string) ($response['failedreason'] ?? 'unknown reason') : 'malformed response';

            throw new RuntimeException("SSLCommerz session initiation failed: {$reason}");
        }

        return new GatewayIntentResult(
            gatewayReference: $tranId,
            redirectUrl: (string) $response['GatewayPageURL'],
            rawResponse: $response,
        );
    }

    public function verify(Payment $payment): GatewayVerificationResult
    {
        $valId = $this->latestValId($payment);

        // Prefer the val_id an IPN already delivered. Mobile financial
        // services in Bangladesh routinely deliver a paid transaction with
        // a delayed or missing IPN (docs/05 §"Payment intent expiry"), so
        // the sweeper's pre-check falls back to a tran_id lookup — the
        // same validator endpoint supports both query shapes — rather than
        // reporting "pending" just because no IPN arrived yet.
        $response = $valId !== null
            ? $this->queryByValId($valId)
            : $this->queryByTranId($payment->payment_number);

        if ($response === null) {
            return new GatewayVerificationResult(
                status: GatewayVerificationResult::STATUS_PENDING,
                settledAmountPaisa: null,
                gatewayTransactionId: null,
                rawResponse: ['reason' => 'no_transaction_found'],
            );
        }

        $status = (string) ($response['status'] ?? '');
        $gatewayTransactionId = isset($response['bank_tran_id']) ? (string) $response['bank_tran_id'] : null;

        if (! in_array($status, [self::STATUS_VALID, self::STATUS_VALIDATED], true)) {
            return new GatewayVerificationResult(
                status: $this->isTerminalFailureStatus($status)
                    ? GatewayVerificationResult::STATUS_FAILED
                    : GatewayVerificationResult::STATUS_PENDING,
                settledAmountPaisa: null,
                gatewayTransactionId: $gatewayTransactionId,
                rawResponse: $response,
            );
        }

        return new GatewayVerificationResult(
            status: GatewayVerificationResult::STATUS_SUCCEEDED,
            settledAmountPaisa: $this->toPaisa((string) ($response['amount'] ?? '0')),
            gatewayTransactionId: $gatewayTransactionId,
            rawResponse: $response,
        );
    }

    public function refund(Payment $payment, int $amountPaisa, string $reason): GatewayRefundResult
    {
        $bankTranId = $payment->gateway_transaction_id;

        if ($bankTranId === null) {
            throw new RuntimeException('Cannot refund a payment with no bank_tran_id on record.');
        }

        $response = Http::asForm()->post($this->refundUrl(), [
            'store_id' => $this->storeId(),
            'store_passwd' => $this->storePassword(),
            'bank_tran_id' => $bankTranId,
            'refund_amount' => $this->toDecimalBdt($amountPaisa),
            'refund_remarks' => $reason,
            'refe_id' => $payment->payment_number.'-refund-'.substr(md5(uniqid('', true)), 0, 8),
            'format' => 'json',
        ])->throw()->json();

        $response = is_array($response) ? $response : [];
        $status = strtolower((string) ($response['status'] ?? ''));
        $succeeded = in_array($status, ['success', 'processing'], true);

        return new GatewayRefundResult(
            status: $succeeded ? GatewayRefundResult::STATUS_SUCCEEDED : GatewayRefundResult::STATUS_FAILED,
            gatewayReference: isset($response['refund_ref_id']) ? (string) $response['refund_ref_id'] : null,
            rawResponse: $response,
        );
    }

    public function parseWebhook(Request $request): GatewayWebhookResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->request->all() + $request->query->all();

        $tranId = (string) ($payload['tran_id'] ?? '');
        $sslStatus = (string) ($payload['status'] ?? '');
        $verifyKey = (string) ($payload['verify_key'] ?? '');
        $verifySign = (string) ($payload['verify_sign'] ?? '');

        $signatureValid = $tranId !== ''
            && $sslStatus !== ''
            && $verifyKey !== ''
            && $verifySign !== ''
            && $this->verifySignature($payload, $verifyKey, $verifySign);

        return new GatewayWebhookResult(
            gatewayReference: $tranId,
            gatewayTransactionId: isset($payload['bank_tran_id']) ? (string) $payload['bank_tran_id'] : null,
            status: $this->normalizeIpnStatus($sslStatus),
            signatureValid: $signatureValid,
            rawPayload: $payload,
        );
    }

    /**
     * SSLCommerz's signature scheme, matching their own PHP library's
     * `SSLCOMMERZ_hash_verify()` (sslcommerz/SSLCommerz-PHP,
     * lib/SslCommerzNotification.php):
     *
     *   1. `verify_key` names the fields that make up the signed string.
     *   2. Collect those fields' values from the payload.
     *   3. Add `store_passwd` => md5(store password) **into** that array.
     *   4. `ksort()` the whole array — including store_passwd.
     *   5. Join as `key=value&…`, drop the trailing `&`, md5 the result.
     *
     * Steps 3–4 are the subtle part and were previously wrong here:
     * `store_passwd` was appended *last* and nothing was sorted, which
     * produces a different digest for any payload carrying a field that
     * sorts after "store_passwd" — `tran_id` and `value_a`, for two that
     * SSLCommerz always sends. The effect was that every genuine IPN
     * failed verification, so no gateway payment could ever reach
     * `succeeded`. The v4 docs describe `verify_sign` only as a "Data
     * Validation Key" and do not publish the algorithm, so their library
     * is the reference.
     *
     * A field named in `verify_key` but absent from the payload is skipped
     * rather than sent as an empty string, again matching the library's
     * `isset()` guard — including it would change the digest.
     *
     * @param  array<string, mixed>  $payload
     */
    private function verifySignature(array $payload, string $verifyKey, string $verifySign): bool
    {
        $storePassword = $this->storePassword();

        if ($storePassword === null || $storePassword === '') {
            return false;
        }

        $signed = [];

        foreach (array_filter(array_map('trim', explode(',', $verifyKey))) as $field) {
            if (array_key_exists($field, $payload)) {
                $signed[$field] = (string) $payload[$field];
            }
        }

        $signed['store_passwd'] = md5($storePassword);

        ksort($signed);

        $pairs = [];
        foreach ($signed as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return hash_equals(md5(implode('&', $pairs)), $verifySign);
    }

    private function normalizeIpnStatus(string $sslStatus): string
    {
        return match (true) {
            in_array($sslStatus, [self::STATUS_VALID, self::STATUS_VALIDATED], true) => GatewayVerificationResult::STATUS_SUCCEEDED,
            $this->isTerminalFailureStatus($sslStatus) => GatewayVerificationResult::STATUS_FAILED,
            default => GatewayVerificationResult::STATUS_PENDING,
        };
    }

    private function isTerminalFailureStatus(string $status): bool
    {
        return in_array($status, [
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_UNATTEMPTED,
            self::STATUS_INVALID_TRANSACTION,
        ], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function queryByValId(string $valId): ?array
    {
        $response = Http::get($this->validationUrl(), [
            'val_id' => $valId,
            'store_id' => $this->storeId(),
            'store_passwd' => $this->storePassword(),
            'format' => 'json',
        ])->throw()->json();

        return is_array($response) ? $response : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function queryByTranId(string $tranId): ?array
    {
        $response = Http::get($this->validationUrl(), [
            'tran_id' => $tranId,
            'store_id' => $this->storeId(),
            'store_passwd' => $this->storePassword(),
            'format' => 'json',
        ])->throw()->json();

        if (! is_array($response) || (int) ($response['no_of_trans_found'] ?? 0) < 1) {
            return null;
        }

        $elements = $response['element'] ?? null;

        return is_array($elements) && isset($elements[0]) && is_array($elements[0]) ? $elements[0] : null;
    }

    private function latestValId(Payment $payment): ?string
    {
        $transaction = $payment->transactions()
            ->where('type', 'ipn')
            ->where('signature_valid', true)
            ->latest('id')
            ->first();

        if ($transaction === null) {
            return null;
        }

        $valId = $transaction->request_payload['val_id'] ?? null;

        return is_string($valId) && $valId !== '' ? $valId : null;
    }

    private function toDecimalBdt(int $paisa): string
    {
        return number_format($paisa / 100, 2, '.', '');
    }

    private function toPaisa(string $bdt): int
    {
        return (int) round(((float) $bdt) * 100);
    }

    private function sessionUrl(): string
    {
        return rtrim((string) config('services.sslcommerz.base_url'), '/').'/gwprocess/v4/api.php';
    }

    private function validationUrl(): string
    {
        return rtrim((string) config('services.sslcommerz.validation_base_url'), '/').'/validator/api/validationserverAPI.php';
    }

    private function refundUrl(): string
    {
        return rtrim((string) config('services.sslcommerz.validation_base_url'), '/').'/validator/api/merchantTransIDvalidationAPI.php';
    }

    private function storeId(): string
    {
        return (string) config('services.sslcommerz.store_id');
    }

    private function storePassword(): ?string
    {
        $password = config('services.sslcommerz.store_password');

        return $password !== null ? (string) $password : null;
    }

    private function returnUrl(string $status, string $frontendCallbackUrl): string
    {
        return route('api.v1.public.payments.sslcommerz.return', [
            'status' => $status,
            'next' => $frontendCallbackUrl,
        ]);
    }
}
