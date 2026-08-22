<?php

namespace App\Domain\Notification\Mail;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Shared\Models\EventSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * Wraps an already-rendered `notifications.body_rendered` row in the
 * designed shell at `resources/views/emails/notification.blade.php`.
 *
 * The split is deliberate. Interpolating the template happened earlier,
 * in {@see QueueNotification}, from copy an Event Manager may edit in the
 * admin console — so the body arrives here as raw HTML, not a Blade view.
 * Everything structural around it (masthead, ticket card, QR panel, notes
 * strip, footer) is code the editor cannot reach: a ticket email's QR is
 * what gets its holder through the gate, and a mis-saved template must
 * not be able to drop it.
 */
class NotificationMail extends Mailable
{
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $bodyHtml,
        private readonly ?MailPresentation $presentation = null,
        private readonly string $bodyLocale = 'en',
    ) {
        // Laravel wraps the whole render in this, so every `__()` below and
        // in the view resolves against the language the notification was
        // written in rather than the app default.
        $this->locale($bodyLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $appName = (string) config('app.name');
        $eventName = $this->setting('event.name') ?? $appName;

        return new Content(
            view: 'emails.notification',
            with: [
                'subject' => $this->subjectLine,
                'bodyHtml' => $this->bodyHtml,
                'locale' => $this->bodyLocale,
                'preheader' => $this->preheader(),
                'eventName' => $eventName,
                'year' => now()->format('Y'),

                // Omitted unless the notifiable names one — "Admission
                // ticket" is true of a ticket and false of a staff
                // key-rotation notice, which shares this shell.
                'mastheadKicker' => $this->presentation?->mastheadKicker,

                // Every email gets a headline; without one of its own it
                // reuses the subject, which is already written to be read
                // at a glance.
                'headline' => $this->presentation->headline ?? $this->subjectLine,
                'headlineAccent' => $this->presentation?->headlineAccent,

                'cardEyebrow' => $this->presentation?->cardEyebrow,
                'cardTitle' => $this->presentation?->cardTitle,
                'cardSubtitle' => $this->presentation?->cardSubtitle,

                'qrPng' => $this->presentation?->qrPng,
                'qrAlt' => $this->presentation->qrAlt ?? __('emails.qr_heading'),
                'qrHeading' => $this->presentation->qrHeading ?? __('emails.qr_heading'),
                'qrCaption' => $this->presentation->qrCaption ?? __('emails.qr_caption_generic'),
                'ticketIdLabel' => $this->presentation?->ticketIdLabel,
                'ticketId' => $this->presentation?->ticketId,

                'facts' => $this->presentation->facts ?? [],
                'notes' => $this->presentation->notes ?? [],
                'icons' => $this->icons(),

                'ctaUrl' => $this->presentation?->ctaUrl,
                'ctaLabel' => $this->presentation->ctaLabel ?? __('emails.cta_generic'),

                'supportHeading' => __('emails.support_heading'),
                'supportLine' => $this->supportLine(),

                'footerTagline' => $this->setting('event.tagline') ?? __('emails.footer_tagline'),
                'footerNote' => $this->presentation->footerNote ?? __('emails.footer_note'),
                'footerAddressLine' => __('emails.footer_reply'),
                'rightsLine' => __('emails.rights'),
            ],
        );
    }

    /**
     * Icon bytes for exactly the glyphs this message asks for, keyed by
     * name. Loaded here rather than in the view so the template never
     * touches the filesystem, and so an unknown name is dropped before it
     * can render a broken image.
     *
     * @return array<string, string>
     */
    private function icons(): array
    {
        if ($this->presentation === null) {
            return [];
        }

        $icons = [];

        foreach ($this->presentation->iconNames() as $name) {
            $path = resource_path("images/email/{$name}.png");

            if (is_file($path)) {
                $icons[$name] = (string) file_get_contents($path);
            }
        }

        return $icons;
    }

    /**
     * Rendered only when someone has actually filled the settings in —
     * a "contact us at" line with nothing after it is worse than no line.
     */
    private function supportLine(): ?string
    {
        $parts = array_filter([
            $this->setting('event.support_email'),
            $this->setting('event.support_phone'),
        ]);

        return $parts === [] ? null : implode('  ·  ', $parts);
    }

    /**
     * The line a mail client shows next to the subject. Taken from the
     * body itself so it always describes this message rather than a
     * generic strapline, and stripped of markup because it is rendered as
     * text.
     */
    private function preheader(): string
    {
        $text = trim(html_entity_decode(strip_tags($this->bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return $text === '' ? $this->subjectLine : Str::limit($text, 140);
    }

    private function setting(string $key): ?string
    {
        $value = EventSetting::query()->where('key', $key)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
