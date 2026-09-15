<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Exceptions\PaymentNotPayableException;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds — or mints — the `pending` payment row a "Pay now" click should
 * open a gateway session on.
 *
 * A registration is created with exactly one payment, and a gateway
 * session can be opened on a row only once: PayStation treats the invoice
 * number as single-use, and answers a second `/initiate-payment` for it
 * with a duplicate-invoice error. So every attempt after the first needs a
 * fresh row, and this is where that row comes from. Without it, a payer
 * who closed the checkout tab, was declined, or let the intent expire was
 * told "couldn't start payment" forever — while the failure notification
 * they had just received invited them to retry.
 *
 * Capacity follows the payment's history, not the click. A declined or
 * expired attempt already gave its seat back (VerifyPayment::markFailed,
 * ExpirePaymentIntents), so a retry has to win it again and may find the
 * event sold out in the meantime. An abandoned checkout never released
 * anything, so its replacement inherits the reservation as-is.
 */
class ResolvePayablePayment
{
    public function __construct(private readonly VerifyPayment $verifyPayment) {}

    /**
     * @throws PaymentNotPayableException
     */
    public function handle(Registration $registration): Payment
    {
        if (! in_array($registration->status, ['draft', 'pending_payment'], true)) {
            throw PaymentNotPayableException::nothingToPay();
        }

        $latest = $registration->payments()->latest('id')->first();

        if ($latest === null) {
            throw PaymentNotPayableException::nothingToPay();
        }

        return match ($latest->status) {
            'pending' => $latest,
            'initiated' => $this->replaceAbandoned($latest),
            'failed', 'expired', 'cancelled' => $this->replaceReleased($latest),
            'processing', 'awaiting_verification' => throw PaymentNotPayableException::inProgress(),
            default => throw PaymentNotPayableException::nothingToPay(),
        };
    }

    /**
     * A session was opened but never settled — as far as we know. The
     * gateway is asked first, server-to-server, because an IPN can be
     * delayed or lost (routine for Bangladeshi MFS) and "the payer came
     * back and pressed Pay again" is exactly the moment a paid-but-unnoticed
     * payment would surface.
     */
    private function replaceAbandoned(Payment $abandoned): Payment
    {
        return match ($this->verifyPayment->handle($abandoned)) {
            VerifyPayment::OUTCOME_SUCCEEDED => throw PaymentNotPayableException::alreadyPaid(),
            VerifyPayment::OUTCOME_AMOUNT_MISMATCH => throw PaymentNotPayableException::underReview(),
            // markFailed released the seat, so this is now an ordinary retry.
            VerifyPayment::OUTCOME_FAILED => $this->replaceReleased($abandoned->refresh()),
            default => DB::transaction(function () use ($abandoned): Payment {
                $abandoned->transitionTo('cancelled', ['failed_at' => now()]);

                // Deliberately no releaseReservation(): the seat stays with
                // the registration and passes to the new row.
                return $this->fresh($abandoned);
            }),
        };
    }

    private function replaceReleased(Payment $previous): Payment
    {
        return DB::transaction(function () use ($previous): Payment {
            $ticketType = $previous->registration?->ticketType;

            if ($ticketType !== null && ! $ticketType->tryReserve()) {
                throw PaymentNotPayableException::soldOut();
            }

            return $this->fresh($previous);
        });
    }

    /** The same debt on a new row — mirrors CreateRegistration's payment. */
    private function fresh(Payment $previous): Payment
    {
        return Payment::create([
            'payment_number' => 'PAY-'.Str::upper(Str::random(8)),
            'registration_id' => $previous->registration_id,
            'attendee_id' => $previous->attendee_id,
            'method' => $previous->method,
            'channel' => $previous->channel,
            'status' => 'pending',
            'amount_due_paisa' => $previous->amount_due_paisa,
            'amount_paid_paisa' => 0,
            'currency' => $previous->currency,
            'idempotency_key' => Str::random(32),
            // A counter sale carries no TTL and its retry does not grow one;
            // an online attempt gets a full window again, since the clock on
            // the old row measured a checkout the payer has already left.
            'expires_at' => $previous->expires_at === null ? null : now()->addMinutes(Payment::intentTtlMinutes()),
        ]);
    }
}
