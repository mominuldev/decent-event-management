<?php

namespace App\Domain\Payment\Events;

use App\Domain\Payment\Models\Payment;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once an Event Manager approves a manual (personal-transfer)
 * payment (docs/01 §1.3 module boundary). Distinct from
 * {@see PaymentSucceeded} because the channel matrix (docs/01 §1.6)
 * sends a different notification for admin-approved manual payments
 * than for a gateway-verified one.
 */
class ManualPaymentVerified
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly User $verifiedBy,
    ) {}
}
