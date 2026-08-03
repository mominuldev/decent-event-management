<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Starts an online-gateway payment session (docs/05 §5.3). Manual
 * (personal-wallet) payments never go through this Action.
 */
class InitiatePayment
{
    public function __construct(private readonly PaymentGatewayResolver $gateways) {}

    /**
     * @return array{payment: Payment, redirect_url: string}
     */
    public function handle(Payment $payment, string $callbackUrl): array
    {
        return DB::transaction(function () use ($payment, $callbackUrl): array {
            $gateway = $this->gateways->forMethod($payment->method);

            $result = $gateway->createIntent($payment, $callbackUrl);

            $payment->transitionTo('initiated', [
                'gateway_reference' => $result->gatewayReference,
                'initiated_at' => now(),
            ]);

            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'type' => 'initiate',
                'direction' => 'outbound',
                'gateway' => $payment->method,
                'status' => 'success',
                'amount_paisa' => $payment->amount_due_paisa,
                'currency' => $payment->currency,
                'gateway_reference' => $result->gatewayReference,
                'response_payload' => $result->rawResponse,
            ]);

            return ['payment' => $payment, 'redirect_url' => $result->redirectUrl];
        });
    }
}
