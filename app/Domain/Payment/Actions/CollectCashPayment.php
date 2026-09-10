<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Exceptions\CashCollectionRejectedException;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Support\RegistrationContext;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Jobs\IssueTicketForRegistrationJob;
use Illuminate\Support\Facades\DB;

/**
 * Records money handed over in cash at a desk, settles the payment and
 * queues the ticket.
 *
 * A sibling of {@see VerifyManualPayment} rather than a widening of it.
 * That action's duplicate-`manual_trx_id` guard is the only thing stopping
 * one bank-transfer reference being approved against two payments, and
 * cash has no such reference to check — so reusing it would have meant
 * deleting the guard for every caller to serve this one.
 *
 * What it will settle is deliberately narrow. `pending` and
 * `awaiting_verification` only: a payment that reached `initiated` has a
 * live session at the gateway, and taking cash for it here would leave the
 * attendee liable to be charged twice. That still covers the two cases
 * that matter — a registration this desk just created, and a walk-in who
 * abandoned an online checkout and turned up with notes instead.
 */
class CollectCashPayment
{
    public function execute(
        Payment $payment,
        User $collectedBy,
        int $amountReceivedPaisa,
        ?string $receiptReference = null,
        ?string $note = null,
        ?string $ip = null,
        ?string $requestId = null,
    ): Payment {
        return DB::transaction(function () use (
            $payment,
            $collectedBy,
            $amountReceivedPaisa,
            $receiptReference,
            $note,
            $ip,
            $requestId,
        ): Payment {
            // Re-read under a row lock: two operators on two tills settling
            // the same payment would otherwise both see `pending`, both
            // settle it, and the second would either double-count the sale
            // or throw an unhandled state-machine exception out of an
            // admin endpoint.
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($payment->status, ['pending', 'awaiting_verification'], true)) {
                throw CashCollectionRejectedException::notCollectable($payment->status);
            }

            if ($amountReceivedPaisa !== (int) $payment->amount_due_paisa) {
                throw CashCollectionRejectedException::amountMismatch(
                    $amountReceivedPaisa,
                    (int) $payment->amount_due_paisa,
                );
            }

            $previousMethod = (string) $payment->method;
            $previousChannel = (string) $payment->channel;

            $now = now();

            // `pending → succeeded` is not a legal move (see
            // Payment::TRANSITIONS), so this steps through
            // `awaiting_verification` rather than jumping. The intermediate
            // state is truthful: the money is in the till and a human is
            // about to confirm it.
            if ($payment->status === 'pending') {
                $payment->transitionTo('awaiting_verification');
            }

            $payment->transitionTo('succeeded');

            $payment->method = 'cash';
            // Never anything but `manual`: every sweeper and the nightly
            // reconciliation select on `channel != 'manual'` and then hand
            // the row's method to PaymentGatewayResolver, which throws for
            // `cash`. See RegistrationContext::counter().
            $payment->channel = 'manual';
            $payment->paid_at = $now;
            $payment->verified_by_user_id = max(0, (int) $collectedBy->id);
            $payment->verified_at = $now;
            $payment->amount_paid_paisa = $payment->amount_due_paisa;
            $payment->net_paisa = $payment->amount_due_paisa;
            $payment->verification_note = $this->verificationNote($receiptReference, $note);
            // A cash payment cannot lapse — the money is already in hand —
            // and leaving a stale expiry on a settled row would be a
            // standing invitation for a future sweeper to act on it.
            $payment->expires_at = null;
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
                'type' => 'collect',
                'direction' => 'inbound',
                'gateway' => 'cash',
                'status' => 'success',
                'amount_paisa' => $payment->amount_due_paisa,
                'currency' => $payment->currency,
                'gateway_reference' => $receiptReference,
                // What the row *was* before cash settled it. On the
                // abandoned-checkout path this is the only surviving record
                // that the attendee started at a gateway, which is exactly
                // what someone reconciling a till against the gateway's own
                // settlement report will need.
                'request_payload' => [
                    'previous_method' => $previousMethod,
                    'previous_channel' => $previousChannel,
                    'collected_by_user_id' => (int) $collectedBy->id,
                ],
            ]);

            // Queued rather than issued here, for the same money reason the
            // gateway and manual-verification paths queue it: this method is
            // one transaction, and a throw in issuance — a missing QR signing
            // key being the commonest — would roll back a settlement for cash
            // that is physically in the till. The job carries its own
            // duplicate guard.
            if ($registration !== null) {
                IssueTicketForRegistrationJob::dispatch($registration->id)->afterCommit();
            }

            // Written from the Action, not the controller (D8): taking money
            // is the kind of act that must be auditable however it was
            // triggered, including from a console command or a future
            // reconciliation tool.
            ActivityLog::create([
                'log_name' => 'payment',
                'event' => 'cash_collected',
                'description' => "Collected cash for payment {$payment->payment_number}",
                'causer_type' => $collectedBy->getMorphClass(),
                'causer_id' => $collectedBy->id,
                'subject_type' => $payment->getMorphClass(),
                'subject_id' => $payment->id,
                'properties' => [
                    'amount_paisa' => (int) $payment->amount_due_paisa,
                    'currency' => $payment->currency,
                    'receipt_reference' => $receiptReference,
                    'note' => $note,
                    'previous_method' => $previousMethod,
                    'previous_channel' => $previousChannel,
                    'registration_number' => $registration?->registration_number,
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            // No payment-received notification is dispatched on purpose. The
            // buyer is standing at the desk, and the ticket confirmation that
            // TicketIssued sends already names the amount paid — a second
            // message would be noise, and on the `sms` channel a billed one.
            // {@see RegistrationContext} for the matching decision on the
            // "we received your registration" mail.
            return $payment->refresh();
        });
    }

    private function verificationNote(?string $receiptReference, ?string $note): string
    {
        $parts = ['Cash collected at counter'];

        if ($receiptReference !== null && $receiptReference !== '') {
            $parts[] = "receipt {$receiptReference}";
        }

        if ($note !== null && $note !== '') {
            $parts[] = $note;
        }

        // The column is VARCHAR(255); an over-length note must not turn a
        // completed cash sale into a database error.
        return mb_substr(implode(' — ', $parts), 0, 255);
    }
}
