<?php

namespace App\Mail;

use App\Domain\Shared\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SensitiveParameter;

/**
 * The staff password-reset link.
 *
 * Deliberately NOT sent through the notification outbox, unlike every other
 * email this application sends. Three reasons, and they all point the same
 * way — this message must work when everything else is switched off:
 *
 * - The outbox honours a per-channel kill switch at send time. An operator
 *   who disabled email to stop a notification storm would, without noticing,
 *   have disabled the only way anybody recovers an account.
 * - Outbox bodies come from `notification_templates`, which Event Managers
 *   can edit. A mis-saved template must not be able to remove the link
 *   somebody signs in with.
 * - The outbox is drained by a queue worker. docs/09 §0 is explicit that
 *   Horizon cannot run on shared hosting and the queue is drained from cron,
 *   so a queued reset email could sit for minutes. This one is sent inline:
 *   slower for the request, but it has actually left by the time the caller
 *   is told to check their inbox.
 */
class StaffPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        #[SensitiveParameter] public readonly string $resetUrl,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your '.config('app.name').' password');
    }

    public function content(): Content
    {
        // Both parts, deliberately. An HTML-only message is one of the
        // oldest and most reliable spam signals there is, and a reset email
        // that lands in a junk folder is a recovery path that does not exist.
        // The text part is not a fallback nobody reads — it is half of what
        // decides whether the HTML one is seen at all.
        return new Content(
            view: 'emails.staff-password-reset',
            text: 'emails.staff-password-reset-text',
        );
    }
}
