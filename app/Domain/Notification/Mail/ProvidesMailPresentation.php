<?php

namespace App\Domain\Notification\Mail;

use App\Domain\Notification\Channels\MailDriver;

/**
 * Implemented by a `notifications.notifiable` model that has more to put
 * in its email than the template body — a ticket contributes its QR
 * code, for instance.
 *
 * The interface lives in the Notification module and the implementations
 * live in the modules that own the data (dependency inversion, the same
 * shape as Ticketing's `ScannerFleetStatus` implemented by CheckIn). That
 * is what keeps {@see MailDriver} from importing another module's
 * Eloquent models, which the module-boundary rule forbids.
 *
 * Resolution happens at send time, not when the outbox row is written:
 * ticket assets are rendered asynchronously on the `tickets` lane and are
 * usually not ready at issuance.
 */
interface ProvidesMailPresentation
{
    public function mailPresentation(): ?MailPresentation;
}
