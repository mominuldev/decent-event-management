<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Gateways\Contracts\GatewayIntentResult;
use App\Domain\Payment\Gateways\Contracts\GatewayRefundResult;
use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\Contracts\GatewayWebhookResult;
use App\Domain\Payment\Gateways\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Deterministic stand-in for the four real gateways (Phase 4), so
 * Phase 2 can build and test the payment flow end-to-end without live
 * merchant credentials. Session state is kept in cache, keyed by the
 * gateway reference, so `createIntent` in one request and `verify`/
 * `parseWebhook` in a later one see the same simulated outcome.
 *
 * Deliberately triggerable failure: a payer MSISDN of
 * {@see self::FAILURE_TRIGGER_MSISDN} simulates a declined payment, so
 * tests can exercise the failure path without mocking.
 */
class FakeGateway implements PaymentGatewayInterface
{
    public const string FAILURE_TRIGGER_MSISDN = '8801700000000';

    private const int SESSION_TTL_SECONDS = 86400;

    public function createIntent(Payment $payment, string $callbackUrl): GatewayIntentResult
    {
        $reference = 'FAKE-'.strtoupper(Str::random(20));
        $transactionId = 'FAKETXN'.strtoupper(Str::random(12));
        $declined = $payment->payer_msisdn === self::FAILURE_TRIGGER_MSISDN;

        Cache::put(
            $this->sessionKey($reference),
            [
                'status' => $declined ? GatewayVerificationResult::STATUS_FAILED : GatewayVerificationResult::STATUS_SUCCEEDED,
                'amount_paisa' => $payment->amount_due_paisa,
                'gateway_transaction_id' => $transactionId,
                'callback_url' => $callbackUrl,
            ],
            self::SESSION_TTL_SECONDS,
        );

        return new GatewayIntentResult(
            gatewayReference: $reference,
            redirectUrl: "https://fake-gateway.test/pay/{$reference}",
            rawResponse: ['reference' => $reference, 'callback_url' => $callbackUrl],
        );
    }

    public function verify(Payment $payment): GatewayVerificationResult
    {
        $session = $payment->gateway_reference !== null
            ? Cache::get($this->sessionKey($payment->gateway_reference))
            : null;

        if ($session === null) {
            return new GatewayVerificationResult(
                status: GatewayVerificationResult::STATUS_PENDING,
                settledAmountPaisa: null,
                gatewayTransactionId: null,
                rawResponse: ['reason' => 'no_session_found'],
            );
        }

        return new GatewayVerificationResult(
            status: $session['status'],
            settledAmountPaisa: $session['status'] === GatewayVerificationResult::STATUS_SUCCEEDED
                ? $session['amount_paisa']
                : null,
            gatewayTransactionId: $session['gateway_transaction_id'],
            rawResponse: $session,
        );
    }

    public function refund(Payment $payment, int $amountPaisa, string $reason): GatewayRefundResult
    {
        return new GatewayRefundResult(
            status: GatewayRefundResult::STATUS_SUCCEEDED,
            gatewayReference: 'FAKE-REFUND-'.strtoupper(Str::random(20)),
            rawResponse: ['amount_paisa' => $amountPaisa, 'reason' => $reason],
        );
    }

    public function parseWebhook(Request $request): GatewayWebhookResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $reference = (string) ($payload['gateway_reference'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $signature = (string) ($payload['signature'] ?? '');

        return new GatewayWebhookResult(
            gatewayReference: $reference,
            gatewayTransactionId: isset($payload['gateway_transaction_id']) ? (string) $payload['gateway_transaction_id'] : null,
            status: $status,
            signatureValid: $reference !== '' && $status !== '' && hash_equals($this->expectedSignature($reference, $status), $signature),
            rawPayload: $payload,
        );
    }

    private function expectedSignature(string $reference, string $status): string
    {
        return hash_hmac('sha256', "{$reference}|{$status}", $this->webhookSecret());
    }

    private function webhookSecret(): string
    {
        /** @var string $secret */
        $secret = config('services.fake_gateway.webhook_secret');

        return $secret;
    }

    private function sessionKey(string $reference): string
    {
        return "fake_gateway:session:{$reference}";
    }
}
