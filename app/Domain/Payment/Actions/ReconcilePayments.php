<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Gateways\Contracts\GatewayVerificationResult;
use App\Domain\Payment\Gateways\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Payment\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nightly settlement diff (docs/06 §6.6 "Reconciliation as a security
 * control"; docs/08 Phase 4A). Surfaces two of the three documented
 * mismatch classes:
 *
 * - `matched` — local `succeeded` and a fresh gateway verify() agree, same amount.
 * - `amount_mismatch` — both report success, but settled amounts differ.
 * - `missing_at_gateway` — locally `succeeded` but the gateway reports the
 *   transaction unsettled or unknown — the strongest fraud signal
 *   (docs/06 §6.6): a ticket issued against a payment the gateway never saw.
 *
 * `missing_locally` — a gateway-side transaction with no matching local
 * payment — is deliberately not implemented here. It requires enumerating
 * a gateway's settlement report by date, and
 * {@see PaymentGatewayInterface}
 * only supports looking up a transaction this system already knows
 * about. Building an enumeration contract generically across
 * bKash/Nagad/Rocket/SSLCommerz — whose settlement-export APIs all
 * differ and none are wired up yet — would be guessing at a shape Phase
 * 4B hasn't picked. Flagged here rather than silently skipped, matching
 * how this project treats every other vendor-blocked gap.
 */
class ReconcilePayments
{
    private const int CHUNK_SIZE = 100;

    public function __construct(private readonly PaymentGatewayResolver $gateways) {}

    /**
     * @return array{matched: int, amount_mismatch: int, missing_at_gateway: int, skipped: int}
     */
    public function handle(): array
    {
        $matched = 0;
        $amountMismatch = 0;
        $missingAtGateway = 0;
        $skipped = 0;

        Payment::query()
            ->where('status', 'succeeded')
            ->where('channel', '!=', 'manual')
            ->whereNull('reconciled_at')
            ->chunkById(self::CHUNK_SIZE, function ($payments) use (&$matched, &$amountMismatch, &$missingAtGateway, &$skipped): void {
                foreach ($payments as $payment) {
                    $classification = $this->classify($payment);

                    match ($classification) {
                        null => $skipped++,
                        'matched' => $matched++,
                        'amount_mismatch' => $amountMismatch++,
                        default => $missingAtGateway++,
                    };

                    if ($classification === null) {
                        continue;
                    }

                    $payment->forceFill([
                        'reconciled_at' => now(),
                        'reconciliation_status' => $classification,
                    ])->save();
                }
            });

        return [
            'matched' => $matched,
            'amount_mismatch' => $amountMismatch,
            'missing_at_gateway' => $missingAtGateway,
            'skipped' => $skipped,
        ];
    }

    private function classify(Payment $payment): ?string
    {
        try {
            $result = $this->gateways->forMethod($payment->method)->verify($payment);
        } catch (Throwable $e) {
            // A network blip is not evidence of fraud — leave
            // `reconciled_at` null so tomorrow's run retries, rather than
            // recording a false `missing_at_gateway`.
            Log::warning('Reconciliation could not reach the gateway for a payment; will retry next run.', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($result->status !== GatewayVerificationResult::STATUS_SUCCEEDED) {
            return 'missing_at_gateway';
        }

        if ($result->settledAmountPaisa !== $payment->amount_due_paisa) {
            return 'amount_mismatch';
        }

        return 'matched';
    }
}
