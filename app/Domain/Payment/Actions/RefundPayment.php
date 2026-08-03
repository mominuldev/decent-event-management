<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Events\RefundIssued;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RefundPayment
{
    public function execute(Payment $payment, User $approvedBy, string $reason, ?int $amountPaisa = null, string $type = 'full'): Refund
    {
        return DB::transaction(function () use ($payment, $approvedBy, $reason, $amountPaisa, $type): Refund {
            if ($payment->status !== 'succeeded') {
                throw new InvalidArgumentException("Payment cannot be refunded from status: {$payment->status}");
            }

            $refundAmount = $amountPaisa ?? $payment->amount_due_paisa;

            $refundNumber = 'REF-'.strtoupper(Str::random(8));

            $refund = Refund::create([
                'refund_number' => $refundNumber,
                'payment_id' => $payment->id,
                'registration_id' => $payment->registration_id,
                'amount_paisa' => $refundAmount,
                'reason' => $reason,
                'type' => $type,
                'status' => 'completed',
                'approved_by_user_id' => $approvedBy->id,
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
