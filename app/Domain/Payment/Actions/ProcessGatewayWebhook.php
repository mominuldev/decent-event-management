<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entry point for all four gateway webhooks (docs/05 §5.3, docs/06 §6.6).
 * A webhook is never trusted to set `succeeded` itself — a validly
 * signed one only triggers a fresh server-to-server {@see VerifyPayment}
 * call. An invalid signature is logged against the matched payment and
 * otherwise ignored.
 */
class ProcessGatewayWebhook
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly VerifyPayment $verifyPayment,
    ) {}

    public function handle(string $gatewayName, Request $request): void
    {
        $gateway = $this->gateways->forMethod($gatewayName);
        $result = $gateway->parseWebhook($request);

        $payment = $result->gatewayReference !== ''
            ? Payment::where('gateway_reference', $result->gatewayReference)->first()
            : null;

        if ($payment === null) {
            Log::warning('Gateway webhook referenced an unknown payment.', [
                'gateway' => $gatewayName,
                'gateway_reference' => $result->gatewayReference,
            ]);

            return;
        }

        // Replay protection (docs §6.6): a duplicate webhook for the same
        // reference/status is recorded once and ignored thereafter.
        $idempotencyKey = "webhook:{$gatewayName}:{$result->gatewayReference}:{$result->status}";

        if (PaymentTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'type' => 'ipn',
            'direction' => 'inbound',
            'gateway' => $gatewayName,
            'status' => $result->signatureValid ? 'success' : 'signature_invalid',
            'gateway_reference' => $result->gatewayReference,
            'gateway_transaction_id' => $result->gatewayTransactionId,
            'signature_valid' => $result->signatureValid,
            'request_payload' => $result->rawPayload,
            'ip_address' => $request->ip() !== null ? inet_pton($request->ip()) : null,
            'idempotency_key' => $idempotencyKey,
        ]);

        if (! $result->signatureValid) {
            return;
        }

        $this->verifyPayment->handle($payment);
    }
}
