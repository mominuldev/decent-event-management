<?php

namespace App\Domain\Payment\Events;

use App\Domain\Payment\Actions\RefundPayment;
use App\Domain\Payment\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once a refund is recorded (docs/01 §1.3 module boundary).
 * Notification listens for this rather than being called directly from
 * {@see RefundPayment}.
 */
class RefundIssued
{
    use Dispatchable;

    public function __construct(public readonly Refund $refund) {}
}
