<?php

namespace App\Domain\Registration\Events;

use App\Domain\Registration\Models\Registration;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once a registration and its pending payment are created
 * (docs/01 §1.3 module boundary). Notification listens for this rather
 * than being called directly, so Registration never imports a
 * Notification model or Action.
 */
class RegistrationCreated
{
    use Dispatchable;

    public function __construct(public readonly Registration $registration) {}
}
