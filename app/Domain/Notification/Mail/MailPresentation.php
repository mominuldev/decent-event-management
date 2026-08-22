<?php

namespace App\Domain\Notification\Mail;

use App\Domain\Notification\Channels\MailDriver;

/**
 * What a notifiable contributes to the chrome of its own email — the
 * headline, the ticket card, the QR panel, the notes strip and the call
 * to action that `resources/views/emails/notification.blade.php` renders
 * around the editable template copy.
 *
 * It exists so {@see MailDriver} stays
 * provider- and domain-agnostic: the driver asks the notifiable for one
 * of these and knows nothing about tickets, registrations or payments.
 *
 * Every field is optional, and the shell degrades a section at a time —
 * no facts and no QR means no ticket card at all, so a payment receipt
 * renders as a hero and a footer with nothing missing-looking in between.
 *
 * @see ProvidesMailPresentation
 */
final class MailPresentation
{
    /**
     * Icon names the shell may draw, resolved against
     * `resources/images/email/{name}.png`. Lucide glyphs at the weight the
     * admin console already uses, pre-rendered rather than referenced as
     * SVG because no mail client renders SVG.
     */
    public const array ICONS = [
        'calendar', 'pin', 'person', 'party', 'ticket',
        'idcard', 'shield', 'clock', 'mail', 'mark',
    ];

    /**
     * @param  string|null  $headline  hero headline; falls back to the subject
     * @param  string|null  $headlineAccent  second headline line, in violet
     * @param  string|null  $mastheadKicker  the line under the event name; omitted when null
     * @param  string|null  $cardEyebrow  small caps label above the card title
     * @param  string|null  $cardTitle  what the ticket is for
     * @param  string|null  $cardSubtitle  a session name or strapline under it
     * @param  string|null  $qrPng  raw PNG bytes, embedded as a CID part
     * @param  string|null  $qrAlt  what a client with images blocked shows instead
     * @param  string|null  $qrHeading  small caps label beside the scan instruction
     * @param  string|null  $qrCaption  bilingual instruction; newlines become <br>
     * @param  string|null  $ticketIdLabel  caps label above the identifier
     * @param  string|null  $ticketId  the identifier itself, in monospace
     * @param  array<int, array{icon: string, label: string, value: string, note?: string|null}>  $facts
     * @param  array<int, array{icon: string, label: string, text: string}>  $notes
     */
    public function __construct(
        public readonly ?string $headline = null,
        public readonly ?string $headlineAccent = null,
        public readonly ?string $mastheadKicker = null,
        public readonly ?string $cardEyebrow = null,
        public readonly ?string $cardTitle = null,
        public readonly ?string $cardSubtitle = null,
        public readonly ?string $qrPng = null,
        public readonly ?string $qrAlt = null,
        public readonly ?string $qrHeading = null,
        public readonly ?string $qrCaption = null,
        public readonly ?string $ticketIdLabel = null,
        public readonly ?string $ticketId = null,
        public readonly array $facts = [],
        public readonly array $notes = [],
        public readonly ?string $ctaUrl = null,
        public readonly ?string $ctaLabel = null,
        public readonly ?string $footerNote = null,
    ) {}

    /**
     * The icon files this presentation actually asks for — only these are
     * loaded and only these travel with the message.
     *
     * @return array<int, string>
     */
    public function iconNames(): array
    {
        $names = ['mark', 'ticket'];

        foreach ($this->facts as $fact) {
            $names[] = $fact['icon'];
        }

        foreach ($this->notes as $note) {
            $names[] = $note['icon'];
        }

        return array_values(array_intersect(array_unique($names), self::ICONS));
    }
}
