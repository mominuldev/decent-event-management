<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Notification\Mail\MailPresentation;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Support\BanglaNumerals;
use App\Domain\Ticketing\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the ticket card, QR panel and notes strip a ticket email is
 * wrapped in.
 *
 * Everything here is resolved at *send* time, which is the point of the
 * class. `GenerateTicketAssetsJob` renders the stored QR PNG on the
 * `tickets` lane while the notification drains on `notifications`, so the
 * two race and the stored image is usually not there yet when the email
 * goes out. Rather than delay delivery or ship a QR-less ticket, this
 * falls back to rendering the code from the signed payload the ticket
 * already carries — the same deterministic bytes the job would have
 * produced, for a few milliseconds of CPU.
 *
 * Every string it emits comes from `lang/{locale}/emails.php` and every
 * date from Carbon under the same locale, because `MailDriver` builds this
 * inside the notification's language. Nothing here is bilingual: the
 * message is written in one language, the one the recipient reads.
 */
class TicketMailPresentation
{
    public function __construct(private readonly RenderTicketQrImage $qrImage) {}

    public function for(Ticket $ticket): MailPresentation
    {
        $ticket->loadMissing(['ticketType', 'eventSession', 'qrCode.image', 'registration', 'attendee']);

        $session = $ticket->eventSession;

        return new MailPresentation(
            headline: $this->line('headline'),
            headlineAccent: $this->line('headline_accent'),
            mastheadKicker: $this->line('kicker'),
            cardEyebrow: $this->line('card_eyebrow'),
            cardTitle: $this->setting('event.name') ?? config('app.name'),
            cardSubtitle: $session?->name,
            qrPng: $this->qrPng($ticket),
            qrAlt: $this->line('qr_alt', ['number' => $ticket->ticket_number]),
            qrHeading: $this->line('qr_heading'),
            qrCaption: $this->line('qr_caption'),
            ticketIdLabel: $this->line('ticket_id_label'),
            ticketId: $ticket->ticket_number,
            facts: $this->facts($ticket),
            notes: $this->notes(),
            ctaUrl: $this->registrationUrl($ticket),
            ctaLabel: $this->line('cta'),
            footerNote: $this->line('footer_note'),
        );
    }

    /**
     * @return array<int, array{icon: string, label: string, value: string, note?: string|null}>
     */
    private function facts(Ticket $ticket): array
    {
        $session = $ticket->eventSession;
        $venue = $session->venue ?? $this->setting('event.venue');
        // Both session timestamps are NOT NULL, so a session either supplies
        // the whole date-and-time line or the event-level date does.
        $when = $session !== null
            ? $this->formatDate($session->starts_at)
            : $this->eventDate();
        $time = $session !== null
            ? $this->formatTime($session->starts_at).' – '.$this->formatTime($session->ends_at)
            : null;

        $facts = [];

        if ($when !== null) {
            $facts[] = ['icon' => 'calendar', 'label' => $this->line('fact.date'), 'value' => $when, 'note' => $time];
        }

        if ($venue !== null) {
            $address = $this->setting('event.venue_address');

            $facts[] = [
                'icon' => 'pin',
                'label' => $this->line('fact.venue'),
                'value' => $venue,
                // An address identical to the venue name reads as a
                // rendering fault, and setting both to the same string is an
                // easy thing to do in the settings screen.
                'note' => $address === $venue ? null : $address,
            ];
        }

        $facts[] = [
            'icon' => 'person',
            'label' => $this->line('fact.attendee'),
            'value' => $this->holderName($ticket),
            'note' => $ticket->holder_batch_year !== null
                ? $this->line('batch', ['year' => $this->localiseDigits((string) $ticket->holder_batch_year)])
                : null,
        ];

        $facts[] = [
            'icon' => 'ticket',
            'label' => $this->line('fact.ticket_type'),
            'value' => $this->preferred($ticket->ticketType?->name_bn, $ticket->ticketType->name ?? $ticket->holder_type_label),
            'note' => null,
        ];

        $facts[] = [
            'icon' => 'party',
            'label' => $this->line('fact.admits'),
            'value' => trans_choice('emails.admits_count', $ticket->admits_total, [
                'count' => $this->localiseDigits((string) $ticket->admits_total),
            ]),
            'note' => null,
        ];

        return $facts;
    }

    /**
     * The four things a holder needs to know before they travel. Fixed
     * copy rather than a setting: each one restates a rule the system
     * actually enforces at the gate, so it must not drift from the code.
     *
     * @return array<int, array{icon: string, label: string, text: string}>
     */
    private function notes(): array
    {
        return [
            ['icon' => 'idcard', 'label' => $this->line('notes.id.label'), 'text' => $this->line('notes.id.text')],
            ['icon' => 'shield', 'label' => $this->line('notes.transfer.label'), 'text' => $this->line('notes.transfer.text')],
            ['icon' => 'clock', 'label' => $this->line('notes.early.label'), 'text' => $this->line('notes.early.text', ['minutes' => $this->localiseDigits('30')])],
            ['icon' => 'mail', 'label' => $this->line('notes.keep.label'), 'text' => $this->line('notes.keep.text')],
        ];
    }

    private function qrPng(Ticket $ticket): ?string
    {
        $qrCode = $ticket->qrCode;

        if ($qrCode === null) {
            // A ticket with no qr_codes row cannot admit anyone, and an
            // empty panel reads as a rendering fault. Drop the QR; the
            // card still tells the reader what they hold.
            return null;
        }

        $stored = $qrCode->image;

        if ($stored !== null) {
            $binary = Storage::disk($stored->disk)->get($stored->path);

            if ($binary !== null) {
                return $binary;
            }
        }

        return $this->qrImage->render($qrCode->payload);
    }

    /**
     * The public site's registration page — the one URL the payment return
     * legs already prove exists (`SslCommerzReturnController`). Built from
     * `services.frontend.url` server-side, never from a request.
     */
    private function registrationUrl(Ticket $ticket): ?string
    {
        $base = rtrim((string) config('services.frontend.url'), '/');
        $ulid = $ticket->registration?->ulid;

        if ($base === '' || $ulid === null) {
            return null;
        }

        return "{$base}/registrations/{$ulid}";
    }

    private function eventDate(): ?string
    {
        $value = $this->setting('event.date');

        if ($value === null) {
            return null;
        }

        try {
            return $this->formatDate(Carbon::parse($value));
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Carbon's `bn` locale translates the month name and the meridiem but
     * leaves the digits Latin, so the numerals are a second step.
     */
    private function formatDate(CarbonInterface $moment): string
    {
        return $this->localiseDigits($this->inEventTimezone($moment)->isoFormat('D MMMM YYYY'));
    }

    private function formatTime(CarbonInterface $moment): string
    {
        return $this->localiseDigits($this->inEventTimezone($moment)->isoFormat('h:mm A'));
    }

    private function inEventTimezone(CarbonInterface $moment): CarbonInterface
    {
        // setTimezone(), not timezone(): the latter doubles as a getter, so
        // it is typed to hand back a string and cannot be chained.
        return $moment->copy()
            ->setTimezone((string) config('app.timezone'))
            ->locale(App::getLocale());
    }

    /**
     * The name to put on the card, in the message's own language.
     *
     * `tickets.holder_name_bn` is a snapshot taken at issuance, and it is
     * empty on every ticket issued before that column existed — so the
     * attendee's current name is consulted before giving up and printing
     * the Latin one. The snapshot still wins where it exists: it is what
     * the printed ticket and the gate list say, and an email that
     * disagrees with the paper in someone's hand is worse than an email
     * carrying a stale spelling.
     */
    private function holderName(Ticket $ticket): string
    {
        $attendee = $ticket->attendee;

        return $this->inBangla()
            ? (string) ($ticket->holder_name_bn ?: $attendee?->full_name_bn ?: $ticket->holder_name ?: $attendee?->full_name)
            : (string) ($ticket->holder_name ?: $attendee?->full_name ?: $ticket->holder_name_bn ?: $attendee?->full_name_bn);
    }

    /**
     * The Bangla wording when the message is Bangla and there is one, the
     * Latin wording otherwise — either may be missing on rows that predate
     * the field, so each is the other's fallback.
     */
    private function preferred(?string $bangla, ?string $latin): string
    {
        return $this->inBangla()
            ? (string) ($bangla ?: $latin)
            : (string) ($latin ?: $bangla);
    }

    private function inBangla(): bool
    {
        return str_starts_with(App::getLocale(), 'bn');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        return (string) __("emails.{$key}", $replace);
    }

    private function localiseDigits(string $value): string
    {
        return BanglaNumerals::localise($value, App::getLocale());
    }

    private function setting(string $key): ?string
    {
        $value = EventSetting::query()->where('key', $key)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
