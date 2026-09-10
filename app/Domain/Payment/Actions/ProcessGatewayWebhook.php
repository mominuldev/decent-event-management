<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Gateways\Contracts\GatewayWebhookResult;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entry point for every gateway webhook (docs/05 §5.3, docs/06 §6.6).
 * A webhook is never trusted to set `succeeded` itself — it only ever
 * triggers a fresh server-to-server {@see VerifyPayment} call, which
 * re-reads status and amount from the gateway over a channel the caller
 * cannot influence.
 *
 * A webhook whose signature was checked and *failed* is recorded against
 * the matched payment and otherwise ignored. A webhook from a gateway
 * that publishes no signature scheme at all (PayStation's IPN: their docs
 * say "Auth: None") is allowed to prompt that verification — see
 * {@see GatewayWebhookResult} for
 * why the three states are kept apart, and why an unsigned prompt cannot
 * move money on its own.
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
            'status' => match ($result->authenticity) {
                GatewayWebhookResult::AUTH_VERIFIED => 'success',
                GatewayWebhookResult::AUTH_UNSIGNED => 'unsigned',
                default => 'signature_invalid',
            },
            'gateway_reference' => $result->gatewayReference,
            'gateway_transaction_id' => $result->gatewayTransactionId,
            'signature_valid' => $result->recordedSignatureValid(),
            'request_payload' => $result->rawPayload,
            'ip_address' => $request->ip() !== null ? inet_pton($request->ip()) : null,
            'idempotency_key' => $idempotencyKey,
        ]);

        if (! $result->mayTriggerVerification()) {
            return;
        }

        $this->verifyPayment->handle($payment);
    }
}
