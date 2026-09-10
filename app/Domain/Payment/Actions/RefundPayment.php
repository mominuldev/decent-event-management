<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Events\RefundIssued;
use App\Domain\Payment\Exceptions\OutOfBandRefundRequiredException;
use App\Domain\Payment\Gateways\Contracts\GatewayRefundResult;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records a refund, and — where the gateway allows it — causes one.
 *
 * Three paths, deliberately distinguished in the audit trail rather than
 * flattened into one "refunded" row:
 *
 *  - **manual (personal wallet)** — no gateway was ever involved, so
 *    there is nothing to call and nothing to acknowledge.
 *  - **a gateway with a refund API** — called first, and a decline aborts
 *    before any local state moves.
 *  - **a gateway without one** (PayStation) — the money has to move in
 *    the merchant panel first; this records what a named staff member
 *    asserts already happened there, together with that panel's own
 *    reference. Without both, it refuses: a refunded payment here voids
 *    the ticket and releases the seat, so booking one that never happened
 *    leaves an attendee with no ticket and no money back.
 */
class RefundPayment
{
    public function __construct(private readonly PaymentGatewayResolver $gateways) {}

    public function execute(
        Payment $payment,
        User $approvedBy,
        string $reason,
        ?int $amountPaisa = null,
        string $type = 'full',
        bool $acknowledgedOutOfBand = false,
        ?string $gatewayRefundReference = null,
    ): Refund {
        return DB::transaction(function () use ($payment, $approvedBy, $reason, $amountPaisa, $type, $acknowledgedOutOfBand, $gatewayRefundReference): Refund {
            if ($payment->status !== 'succeeded') {
                throw new InvalidArgumentException("Payment cannot be refunded from status: {$payment->status}");
            }

            $refundAmount = $amountPaisa ?? $payment->amount_due_paisa;

            // A manual (personal-wallet) payment has no gateway to call —
            // the money never moved through one — so the refund is a
            // local record only, same as VerifyManualPayment's approval.
            if ($payment->isManual()) {
                $gatewayResult = new GatewayRefundResult(
                    GatewayRefundResult::STATUS_SUCCEEDED,
                    null,
                    ['reason' => 'manual_payment_no_gateway_call'],
                );
                $transactionStatus = 'success';
            } elseif (! $this->gateways->forMethod($payment->method)->supportsGatewayRefund()) {
                $reference = trim((string) $gatewayRefundReference);

                if (! $acknowledgedOutOfBand || $reference === '') {
                    throw OutOfBandRefundRequiredException::forMethod($payment->method);
                }

                $gatewayResult = new GatewayRefundResult(
                    GatewayRefundResult::STATUS_SUCCEEDED,
                    $reference,
                    [
                        'reason' => 'gateway_has_no_refund_api',
                        'acknowledged_out_of_band' => true,
                        'acknowledged_by_user_id' => $approvedBy->id,
                        'acknowledged_at' => now()->toIso8601String(),
                    ],
                );

                // Not 'success': no request was made to a gateway, and a
                // transaction row claiming one would misread as evidence
                // during a dispute.
                $transactionStatus = 'acknowledged_out_of_band';
            } else {
                $gatewayResult = $this->gateways->forMethod($payment->method)->refund($payment, $refundAmount, $reason);
                $transactionStatus = 'success';

                if (! $gatewayResult->isSucceeded()) {
                    throw new InvalidArgumentException("Gateway declined the refund for payment {$payment->payment_number}.");
                }
            }

            $refundNumber = 'REF-'.strtoupper(Str::random(8));

            // forceFill, not Refund::create(): `approved_by_user_id`,
            // `approved_at`, `processed_at` and `gateway_refund_id` are all
            // outside Refund::$fillable, so create() silently dropped every
            // one of them — no refund in this system had ever recorded who
            // authorised it or when. They stay non-fillable (an authority
            // column must not be settable from any array that happens to
            // carry the key) and are written explicitly here instead, which
            // is the same discipline qr_codes.image_media_id follows.
            $refund = (new Refund)->forceFill([
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

            $refund->save();

            $payment->transactions()->create([
                'type' => 'refund',
                'direction' => 'outbound',
                'gateway' => $payment->method,
                'status' => $transactionStatus,
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
                        // `AND quantity_sold > 0` because the column is
                        // BIGINT UNSIGNED: decrementing a zero raises
                        // SQLSTATE 22003 and takes the whole refund down
                        // with a 500 mid-transaction. It has to be guarded
                        // in the WHERE rather than wrapped in GREATEST —
                        // MySQL evaluates the unsigned subtraction, and
                        // errors, before any surrounding function sees it.
                        // It should never be zero here (confirmSale()
                        // incremented it when the payment settled), but
                        // "should never" is worth guarding when the failure
                        // mode is an operator unable to refund anyone.
                        DB::update('UPDATE ticket_types SET quantity_sold = quantity_sold - 1 WHERE id = ? AND quantity_sold > 0', [$registration->ticketType->id]);
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
