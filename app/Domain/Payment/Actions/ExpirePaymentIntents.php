<?php

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Events\PaymentFailed;
use App\Domain\Payment\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every 5 minutes (routes/console.php). Closes D5: an abandoned
 * checkout must release its reserved capacity, but only after a fresh
 * gateway pre-check — mobile financial services in Bangladesh routinely
 * deliver a successful transaction with a delayed or missing IPN, so
 * expiring a genuinely paid registration is far worse than holding
 * capacity for a few extra minutes (docs/05 §"Payment intent expiry").
 *
 * Manual-channel payments are out of scope here — they have no gateway
 * to pre-check and are governed by the 24h human-verification window
 * instead (docs/05 §5.4), not an intent TTL.
 */
class ExpirePaymentIntents
{
    private const int CHUNK_SIZE = 100;

    public function __construct(private readonly VerifyPayment $verifyPayment) {}

    /**
     * @return array{expired: int, recovered: int, skipped: int}
     */
    public function handle(): array
    {
        $expired = 0;
        $recovered = 0;
        $skipped = 0;

        Payment::query()
            ->whereIn('status', ['pending', 'initiated'])
            ->where('channel', '!=', 'manual')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById(self::CHUNK_SIZE, function ($payments) use (&$expired, &$recovered, &$skipped): void {
                foreach ($payments as $payment) {
                    match ($this->verifyPayment->handle($payment)) {
                        VerifyPayment::OUTCOME_SUCCEEDED => $recovered++,
                        VerifyPayment::OUTCOME_PENDING => $this->expire($payment) ? $expired++ : $skipped++,
                        // failed: VerifyPayment already released capacity and notified.
                        // amount_mismatch: left for reconciliation, never auto-resolved.
                        default => $skipped++,
                    };
                }
            });

        return ['expired' => $expired, 'recovered' => $recovered, 'skipped' => $skipped];
    }

    private function expire(Payment $payment): bool
    {
        if (! $payment->canTransitionTo('expired')) {
            Log::warning('Payment intent expiry sweeper found a payment it cannot transition to expired.', [
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ]);

            return false;
        }

        $payment->transitionTo('expired', ['failed_at' => now()]);

        $payment->registration?->ticketType?->releaseReservation();

        // Reuses the existing payment-failure notification: docs/01 §1.6's
        // channel matrix groups "payment failed / expired" as a single row
        // sharing the same retry-link copy, so a second template/event
        // would be pure duplication.
        PaymentFailed::dispatch($payment);

        return true;
    }
}
