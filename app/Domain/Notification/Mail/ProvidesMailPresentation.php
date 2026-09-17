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
 *
 * The template key is passed because one notifiable can be the subject
 * of more than one kind of email. A ticket is behind both the
 * registration-confirmed message (the share card, no QR) and the ticket
 * itself (the QR, no share card), and only the row's `template_key` says
 * which of the two is being sent.
 */
interface ProvidesMailPresentation
{
    public function mailPresentation(string $templateKey): ?MailPresentation;
}
