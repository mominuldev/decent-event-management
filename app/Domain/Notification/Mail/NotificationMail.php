<?php

namespace App\Domain\Notification\Mail;

use App\Domain\Notification\Actions\QueueNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Thin envelope around an already-rendered `notifications.body_rendered`
 * row — the template rendering happened in
 * {@see QueueNotification}, so this
 * carries raw HTML rather than a Blade view.
 */
class NotificationMail extends Mailable
{
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $bodyHtml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->bodyHtml);
    }
}
