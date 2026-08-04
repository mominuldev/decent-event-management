<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Events\RefundIssued;
use App\Domain\Payment\Gateways\Contracts\GatewayRefundResult;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RefundPayment
{
    public function __construct(private readonly PaymentGatewayResolver $gateways) {}

    public function execute(Payment $payment, User $approvedBy, string $reason, ?int $amountPaisa = null, string $type = 'full'): Refund
    {
        return DB::transaction(function () use ($payment, $approvedBy, $reason, $amountPaisa, $type): Refund {
            if ($payment->status !== 'succeeded') {
                throw new InvalidArgumentException("Payment cannot be refunded from status: {$payment->status}");
            }

            $refundAmount = $amountPaisa ?? $payment->amount_due_paisa;

            // A manual (personal-wallet) payment has no gateway to call —
            // the money never moved through one — so the refund is a
            // local record only, same as VerifyManualPayment's approval.
            $gatewayResult = $payment->isManual()
                ? new GatewayRefundResult(GatewayRefundResult::STATUS_SUCCEEDED, null, ['reason' => 'manual_payment_no_gateway_call'])
                : $this->gateways->forMethod($payment->method)->refund($payment, $refundAmount, $reason);

            if (! $gatewayResult->isSucceeded()) {
                throw new InvalidArgumentException("Gateway declined the refund for payment {$payment->payment_number}.");
            }

            $refundNumber = 'REF-'.strtoupper(Str::random(8));

            $refund = Refund::create([
                'refund_number' => $refundNumber,
                'payment_id' => $payment->id,
                'registration_id' => $payment->registration_id,
                'amount_paisa' => $refundAmount,
                'reason' => $reason,
                'type' => $type,
                'method' => $payment->method,
                'status' => 'completed',
                'approved_by_user_id' => $approvedBy->id,
                'approved_at' => now(),
                'processed_at' => now(),
                'gateway_refund_id' => $gatewayResult->gatewayReference,
            ]);

            $payment->transactions()->create([
                'type' => 'refund',
                'direction' => 'outbound',
                'gateway' => $payment->method,
                'status' => 'success',
                'amount_paisa' => $refundAmount,
                'currency' => $payment->currency,
                'gateway_reference' => $gatewayResult->gatewayReference,
                'response_payload' => $gatewayResult->rawResponse,
            ]);

            $newRefundedPaisa = max(0, (int) ($payment->refunded_paisa + $refundAmount));
            $paymentStatus = $newRefundedPaisa >= $payment->amount_due_paisa ? 'refunded' : 'partially_refunded';

            $payment->transitionTo($paymentStatus);
            $payment->refunded_paisa = $newRefundedPaisa;
            $payment->save();

            $registration = $payment->registration;
            if ($registration !== null) {
                if ($paymentStatus === 'refunded') {
                    if ($registration->canTransitionTo('refunded')) {
                        $registration->transitionTo('refunded');
                        $registration->save();
                    }

                    if ($registration->ticketType !== null) {
                        DB::update('UPDATE ticket_types SET quantity_sold = quantity_sold - 1 WHERE id = ?', [$registration->ticketType->id]);
                    }

                    $tickets = $registration->tickets()->where('status', 'active')->get();
                    foreach ($tickets as $ticket) {
                        $ticket->transitionTo('voided');
                        $ticket->voided_at = now();
                        $ticket->void_reason = "Payment refunded: {$reason}";
                        $ticket->manifest_version++;
                        $ticket->save();

                        if ($ticket->qrCode !== null) {
                            $ticket->qrCode->update(['is_active' => false]);
                        }
                    }
                }
            }

            RefundIssued::dispatch($refund);

            return $refund;
        });
    }
}
