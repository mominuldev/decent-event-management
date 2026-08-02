<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Server-to-server verification (docs/05 §5.3, docs/06 §6.6) — the only
 * path allowed to move a payment to `succeeded`. Callers (the payment
 * intent expiry sweeper, {@see ProcessGatewayWebhook}) trigger this; they
 * never set `succeeded` themselves from a webhook payload or a browser
 * return callback.
 */
class VerifyPayment
{
    public const string OUTCOME_SUCCEEDED = 'succeeded';

    public const string OUTCOME_AMOUNT_MISMATCH = 'amount_mismatch';

    public const string OUTCOME_FAILED = 'failed';

    public const string OUTCOME_PENDING = 'pending';

    public function __construct(private readonly PaymentGatewayResolver $gateways) {}

    public function handle(Payment $payment): string
    {
        // Idempotent by design: a payment already at a terminal status is
        // never re-verified, so a retried webhook or sweeper pass is a
        // no-op rather than an illegal transition.
        if ($payment->status === 'succeeded') {
            return self::OUTCOME_SUCCEEDED;
        }

        if (in_array($payment->status, ['failed', 'rejected'], true)) {
            return self::OUTCOME_FAILED;
        }

        return DB::transaction(function () use ($payment): string {
            $gateway = $this->gateways->forMethod($payment->method);
            $result = $gateway->verify($payment);

            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'type' => 'verify',
                'direction' => 'outbound',
                'gateway' => $payment->method,
                'status' => 'success',
                'amount_paisa' => $result->settledAmountPaisa,
                'currency' => $payment->currency,
                'gateway_transaction_id' => $result->gatewayTransactionId,
                'response_payload' => $result->rawResponse,
            ]);

            return match (true) {
                $result->status === GatewayVerificationResult::STATUS_PENDING => self::OUTCOME_PENDING,
                $result->status === GatewayVerificationResult::STATUS_FAILED => $this->markFailed($payment),
                $result->settledAmountPaisa !== $payment->amount_due_paisa => $this->markAmountMismatch($payment),
                default => $this->markSucceeded($payment, $result),
            };
        });
    }

    private function markSucceeded(Payment $payment, GatewayVerificationResult $result): string
    {
        if ($payment->status === 'initiated') {
            $payment->transitionTo('processing');
        }

        $payment->transitionTo('succeeded', [
            'amount_paid_paisa' => $result->settledAmountPaisa,
            'net_paisa' => $result->settledAmountPaisa,
            'gateway_transaction_id' => $result->gatewayTransactionId,
            'paid_at' => now(),
        ]);

        $registration = $payment->registration;

        if ($registration !== null) {
            if ($registration->canTransitionTo('paid')) {
                $registration->transitionTo('paid');
            }

            $registration->ticketType?->confirmSale();
        }

        return self::OUTCOME_SUCCEEDED;
    }

    private function markFailed(Payment $payment): string
    {
        if ($payment->status === 'initiated') {
            $payment->transitionTo('processing');
        }

        $payment->transitionTo('failed', ['failed_at' => now()]);

        $payment->registration?->ticketType?->releaseReservation();

        return self::OUTCOME_FAILED;
    }

    /**
     * Neither succeeded nor failed — flagged for human review, never
     * auto-resolved (docs/05 §5.3, docs/06 §6.6).
     */
    private function markAmountMismatch(Payment $payment): string
    {
        $payment->forceFill(['reconciliation_status' => 'amount_mismatch'])->save();

        return self::OUTCOME_AMOUNT_MISMATCH;
    }
}
