<?php

namespace App\Domain\Payment\Events;

use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Payment\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once a payment has been moved to `failed` by server-to-server
 * verification (docs/01 §1.3 module boundary). Notification listens for
 * this rather than being called directly from {@see VerifyPayment}.
 */
class PaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
