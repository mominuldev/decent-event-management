<?php

namespace App\Domain\Payment\Events;

use App\Domain\Payment\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once a payment has been moved to `succeeded` by server-to-server
 * verification (docs/01 §1.3 module boundary). Ticketing listens for this
 * rather than being called directly, so Payment never imports a Ticketing
 * model or Action.
 */
class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
