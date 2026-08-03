<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Models\Payment;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Actions\IssueTicket;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VerifyManualPayment
{
    public function execute(Payment $payment, User $verifiedBy, string $note): Payment
    {
        return DB::transaction(function () use ($payment, $verifiedBy, $note): Payment {
            if (! in_array($payment->status, ['awaiting_verification', 'pending'], true)) {
                throw new InvalidArgumentException("Payment cannot be verified from status: {$payment->status}");
            }

            if (empty($payment->manual_trx_id)) {
                throw new InvalidArgumentException('Payment is missing manual_trx_id');
            }

            $duplicateExists = Payment::query()
                ->where('manual_trx_id', $payment->manual_trx_id)
                ->where('status', 'succeeded')
                ->where('id', '!=', $payment->id)
                ->exists();

            if ($duplicateExists) {
                throw new InvalidArgumentException('Duplicate manual_trx_id found on a succeeded payment');
            }

            $now = now();

            $payment->transitionTo('succeeded');
            $payment->paid_at = $now;
            $payment->verified_by_user_id = max(0, (int) $verifiedBy->id);
            $payment->verified_at = $now;
            $payment->amount_paid_paisa = $payment->amount_due_paisa;
            $payment->net_paisa = $payment->amount_due_paisa;
            $payment->verification_note = $note;
            $payment->save();

            $registration = $payment->registration;
            if ($registration !== null) {
                $registration->transitionTo('paid');
                $registration->confirmed_at = $now;
                $registration->save();

                if ($registration->ticketType !== null) {
                    $registration->ticketType->confirmSale(1);
                }
            }

            $payment->transactions()->create([
                'type' => 'verify',
                'direction' => 'inbound',
                'gateway' => 'manual',
                'status' => 'success',
                'amount_paisa' => $payment->amount_due_paisa,
            ]);

            if ($registration !== null) {
                app(IssueTicket::class)->execute($registration);
            }

            return $payment->refresh();
        });
    }
}
