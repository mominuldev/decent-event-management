<?php

namespace App\Domain\Payment\Gateways\Contracts;

use App\Domain\Payment\Models\Payment;
use Illuminate\Http\Request;

/**
 * One contract for all four gateways (bKash, Nagad, Rocket, SSLCommerz —
 * docs/01 §1.7). Gateway-specific quirks (token refresh, RSA payloads,
 * `val_id` calls) stay inside each adapter; domain code only ever sees
 * this interface.
 */
interface PaymentGatewayInterface
{
    public function createIntent(Payment $payment, string $callbackUrl): GatewayIntentResult;

    /**
     * Server-to-server status check. The only path allowed to mark a
     * payment `succeeded` (docs/06 §6.6) — a webhook alone never does.
     */
    public function verify(Payment $payment): GatewayVerificationResult;

    public function refund(Payment $payment, int $amountPaisa, string $reason): GatewayRefundResult;

    /**
     * Parses and authenticates an inbound webhook request. Implementations
     * must set `signatureValid` from real signature verification — callers
     * must not treat a parsed payload as trusted on its own.
     */
    public function parseWebhook(Request $request): GatewayWebhookResult;
}
