<?php

namespace App\Console\Commands;

use App\Domain\Payment\Actions\ExpirePaymentIntents;
use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use App\Jobs\IssueTicketForRegistrationJob;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finds payments the payer believes they made and this system does not:
 * still `pending`/`initiated` locally while the gateway may already have
 * taken the money.
 *
 * That gap used to be manufactured here rather than at the gateway. Ticket
 * issuance ran inside the transaction that settles a payment, so any throw
 * in issuance rolled the settlement back — the payer was charged, the
 * payment reverted to `initiated`, and the return page polled a spinner
 * for ever. Issuance is a queued job now
 * ({@see IssueTicketForRegistrationJob}), so no new payment can
 * be stranded that way; this command is for the ones already taken while
 * it could, and as a standing check afterwards.
 *
 * Read-only unless asked otherwise. `--check` asks each gateway what
 * really happened without writing anything; `--recover` settles the ones
 * it confirms, through {@see VerifyPayment} — the only sanctioned path, so
 * the val_id and amount re-checks still apply and a browser claim is still
 * worth nothing.
 *
 * The scheduled sweeper ({@see ExpirePaymentIntents})
 * recovers these on its own, but only once a payment is past its
 * `expires_at`, and only where the scheduler cron is actually running
 * (docs/09 section 5). This gives an operator the answer now, and on a
 * host where that cron was never set up.
 */
class FindStuckPayments extends Command
{
    protected $signature = 'payments:stuck
        {--check : Ask each gateway what really happened (read-only, makes one outbound call per payment)}
        {--recover : Settle the payments the gateway confirms — implies --check, and writes}
        {--days=30 : Only look at payments created within this many days}';

    protected $description = 'List payments still unsettled locally, and optionally recover ones the gateway has already taken.';

    public function handle(PaymentGatewayResolver $gateways, VerifyPayment $verifyPayment): int
    {
        $recover = (bool) $this->option('recover');
        $check = $recover || (bool) $this->option('check');

        /** @var list<Payment> $payments */
        $payments = Payment::query()
            ->with(['registration', 'attendee'])
            ->whereIn('status', ['pending', 'initiated'])
            ->where('channel', '!=', 'manual')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('created_at')
            ->get()
            ->all();

        if ($payments === []) {
            $this->info('No unsettled online payments in that window.');

            return self::SUCCESS;
        }

        $rows = [];
        $confirmed = 0;
        $recovered = 0;

        foreach ($payments as $payment) {
            $verdict = '—';

            if ($check) {
                $verdict = $this->askGateway($gateways, $payment);

                if ($verdict === GatewayVerificationResult::STATUS_SUCCEEDED) {
                    $confirmed++;

                    if ($recover) {
                        // Through the Action, never by hand: it re-runs the
                        // val_id lookup and re-checks the amount before
                        // anything is marked succeeded.
                        try {
                            $outcome = $verifyPayment->handle($payment);
                            $verdict = "PAID -> {$outcome}";
                            $recovered += $outcome === VerifyPayment::OUTCOME_SUCCEEDED ? 1 : 0;
                        } catch (Throwable $e) {
                            $verdict = 'PAID -> error: '.$e->getMessage();
                        }
                    } else {
                        $verdict = 'PAID AT GATEWAY';
                    }
                }
            }

            // The column is NOT NULL with a restricting FK, so this is
            // belt-and-braces — but the relation is typed nullable and a
            // listing command must not be the thing that fatals mid-incident.
            $registration = $payment->registration;

            $rows[] = [
                $payment->payment_number,
                $registration !== null ? $registration->registration_number : '—',
                $payment->method,
                $payment->status,
                number_format($payment->amount_due_paisa / 100, 2),
                $payment->created_at?->format('Y-m-d H:i') ?? '—',
                $verdict,
            ];
        }

        $this->table(
            ['Payment', 'Registration', 'Method', 'Local status', 'Amount BDT', 'Created', 'Gateway'],
            $rows,
        );

        $this->line(sprintf('%d unsettled payment(s) in the last %s days.', count($rows), $this->option('days')));

        if (! $check) {
            $this->comment('Nothing was asked of any gateway. Re-run with --check to find out which of these were actually paid.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d confirmed paid at the gateway.', $confirmed));

        if ($recover) {
            $this->info(sprintf('%d settled.', $recovered));
        } elseif ($confirmed > 0) {
            $this->comment('Re-run with --recover to settle those, which issues their tickets and notifies the payer.');
        }

        return self::SUCCESS;
    }

    /**
     * Read-only: talks to the gateway directly rather than through
     * VerifyPayment, so listing cannot change a payment's state. A gateway
     * that cannot be reached is reported as such, never guessed at.
     */
    private function askGateway(PaymentGatewayResolver $gateways, Payment $payment): string
    {
        try {
            return $gateways->forMethod($payment->method)->verify($payment)->status;
        } catch (Throwable $e) {
            return 'unreachable: '.$e->getMessage();
        }
    }
}
