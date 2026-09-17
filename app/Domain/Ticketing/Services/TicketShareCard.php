<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Services\HtmlToImageRenderer;
use App\Domain\Shared\Services\HtmlToPdfRenderer;
use App\Domain\Shared\Support\BanglaNumerals;
use App\Domain\Shared\Support\EventSettingCatalogue;
use App\Domain\Ticketing\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The "আমি থাকছি!" share card — an 878×713 image of the holder's ticket,
 * drawn from the registration, that goes out with the confirmation email
 * for the attendee to post. Built to the Figma frame "Event Ticket — v7"
 * (Centennial Celebration — Home Page, node 227:731); the markup is
 * `resources/views/tickets/share-card.blade.php`.
 *
 * Two callers want the same bytes and usually race for them:
 * `GenerateTicketAssetsJob` on the `tickets` lane, and the confirmation
 * email draining on `notifications`. So {@see ensureStored()} is the one
 * entry point — whichever runs first renders and stores, the other finds
 * the row. Losing the race after rendering costs one discarded render,
 * never a duplicate media row: the ticket is re-read under lock before
 * anything is written.
 *
 * A Chrome render is a few seconds, so this is deliberately off the
 * issuance transaction, like the PDF. Unlike the QR it is not the ticket:
 * the email sends without it if rendering fails (see
 * `TicketMailPresentation::shareJpeg()`), which is what keeps a host with
 * no Chromium from losing every confirmation email over a picture.
 */
class TicketShareCard
{
    public const string COLLECTION = 'ticket_share_card';

    public const int WIDTH = 878;

    public const int HEIGHT = 713;

    /** Device pixels per CSS pixel — sharp on a phone, and Facebook's own preferred width is ~1200px. */
    public const int SCALE = 2;

    /**
     * JPEG rather than PNG: the card is a photograph-like gradient with
     * text, and the PNG Chrome produces measures ~870 KB at 2× against
     * ~300 KB as JPEG at this quality — a difference that matters on a
     * message sent 12,000 times and opened on mobile data.
     */
    public const int JPEG_QUALITY = 90;

    public function __construct(
        private readonly HtmlToImageRenderer $images,
        private readonly HtmlToPdfRenderer $fonts,
        private readonly TicketAssetStore $store,
    ) {}

    /**
     * The stored card, rendering and storing it first if there is none.
     */
    public function ensureStored(Ticket $ticket): MediaFile
    {
        $ticket->loadMissing('shareImage');

        if ($ticket->shareImage !== null) {
            return $ticket->shareImage;
        }

        $jpeg = $this->render($ticket);

        return DB::transaction(function () use ($ticket, $jpeg): MediaFile {
            /** @var Ticket $fresh */
            $fresh = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            $existing = $fresh->share_image_media_id !== null ? $fresh->shareImage : null;

            if ($existing !== null) {
                // The other caller got there first; theirs is the record.
                $this->adopt($ticket, $existing);

                return $existing;
            }

            $media = $this->store->put(
                binary: $jpeg,
                collection: self::COLLECTION,
                mimeType: 'image/jpeg',
                extension: 'jpg',
                originalName: $this->fileName($ticket),
            );

            // Outside $fillable, like `pdf_media_id` — nothing request-driven
            // may set it.
            $fresh->forceFill(['share_image_media_id' => $media->id])->save();
            $this->adopt($ticket, $media);

            return $media;
        });
    }

    /** Bring the caller's instance in line with what was just written. */
    private function adopt(Ticket $ticket, MediaFile $media): void
    {
        $ticket->forceFill(['share_image_media_id' => $media->id]);
        $ticket->setRelation('shareImage', $media);
    }

    /**
     * The stored bytes, or a fresh render when nothing is stored yet.
     */
    public function bytes(Ticket $ticket): string
    {
        $media = $this->ensureStored($ticket);
        $binary = Storage::disk($media->disk)->get($media->path);

        // A row whose file has gone (a disk restored from an older backup)
        // is not a reason to send nothing; it is a reason to draw it again.
        return $binary ?? $this->render($ticket);
    }

    public function fileName(Ticket $ticket): string
    {
        return "ami-thakchi-{$ticket->ticket_number}.jpg";
    }

    /**
     * @return string JPEG bytes
     */
    public function render(Ticket $ticket): string
    {
        $png = $this->images->renderPng($this->html($ticket), self::WIDTH, self::HEIGHT, self::SCALE);

        return $this->toJpeg($png);
    }

    /**
     * The card's markup, in the email channel's language. Public so a test
     * can assert on the document without paying for Chrome.
     */
    public function html(Ticket $ticket): string
    {
        $ticket->loadMissing(['eventSession', 'registration', 'attendee']);

        $previous = App::getLocale();
        App::setLocale($this->locale());

        try {
            $batchYear = $ticket->holder_batch_year;

            return view('tickets.share-card', [
                'locale' => App::getLocale(),
                'fontFaceCss' => $this->fonts->fontFaceCss(),
                'wordmarkUrl' => 'file://'.resource_path('images/share/school-wordmark.svg'),
                'logoUrl' => 'file://'.resource_path('images/share/centenary-logo.png'),

                'shout' => $this->line('shout'),
                'kicker' => $this->line('kicker'),
                'headline' => $this->line('headline'),
                'headlineAccent' => $this->line('headline_accent'),

                'attendeeLabel' => $this->line('attendee'),
                'attendeeName' => $this->holderName($ticket),
                'attendeeAbout' => $this->about($ticket),
                'facts' => $this->facts($ticket),

                'registrationLabel' => $this->line('registration'),
                // `registration_id` is NOT NULL; the fallback only guards the relation type.
                'registrationNumber' => $ticket->registration->registration_number ?? $ticket->ticket_number,
                'stubLabel' => $batchYear !== null ? $this->line('stub_batch') : $this->line('stub_role'),
                'stubValue' => $batchYear !== null ? $this->digits((string) $batchYear) : $this->participantLabel($ticket),
                'stubValueIsLong' => $batchYear === null,
                'admits' => $this->line('admits', ['count' => $this->digits((string) $ticket->admits_total)]),

                'invite' => $this->line('invite'),
                'organiser' => $this->line('organiser'),
            ])->render();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * "প্রাক্তন শিক্ষার্থী, এসএসসি ব্যাচ ১৯৯৮। পরিবারসহ ৩ জন।" — who the
     * holder is, and how many they bring. A party of one gets only the
     * first sentence; "with family, 1 person" is not a sentence.
     */
    private function about(Ticket $ticket): string
    {
        $participant = $this->participantLabel($ticket);
        $year = $ticket->holder_batch_year;

        $who = $year !== null
            ? $this->line('about_batch', ['participant' => $participant, 'year' => $this->digits((string) $year)])
            : $this->line('about_no_batch', ['participant' => $participant]);

        if ($ticket->admits_total <= 1) {
            return $who;
        }

        return $who.' '.$this->line('about_party', ['count' => $this->digits((string) $ticket->admits_total)]);
    }

    /**
     * The date is read off the ticket's session (or `event.date`); the
     * time and the venue are campaign copy from `lang/{locale}/share_card.php`,
     * fixed to what the Figma frame ("Event Ticket — v6", 202:1079) shows,
     * so the card says the same thing whichever session a ticket lands on
     * and never loses a column for want of one.
     *
     * @return array<int, array{label: string, value: string, note: string|null}>
     */
    private function facts(Ticket $ticket): array
    {
        $session = $ticket->eventSession;
        $facts = [];

        $date = $session !== null ? $this->inEventTimezone($session->starts_at) : $this->eventDate();

        if ($date !== null) {
            $facts[] = [
                'label' => $this->line('fact.date'),
                'value' => $this->digits($date->isoFormat('D MMMM YYYY')),
                'note' => $date->isoFormat('dddd'),
            ];
        }

        $facts[] = [
            'label' => $this->line('fact.time'),
            'value' => $this->line('fact.time_value'),
            'note' => $this->line('fact.time_note'),
        ];

        $facts[] = [
            'label' => $this->line('fact.venue'),
            'value' => $this->line('fact.venue_value'),
            'note' => $this->line('fact.venue_note'),
        ];

        return $facts;
    }

    private function participantLabel(Ticket $ticket): string
    {
        $type = $ticket->holder_type_label ?: $ticket->attendee?->participant_type;
        $key = "share_card.participant.{$type}";
        $label = __($key);

        // An unknown type falls through as its own key; the raw slug is
        // a better card than "share_card.participant.foo".
        return is_string($label) && $label !== $key ? $label : (string) $type;
    }

    /**
     * Same precedence as the email: the issuance snapshot in the card's
     * language, then the attendee's current name, then the other script.
     */
    private function holderName(Ticket $ticket): string
    {
        $attendee = $ticket->attendee;

        return $this->inBangla()
            ? (string) ($ticket->holder_name_bn ?: $attendee?->full_name_bn ?: $ticket->holder_name ?: $attendee?->full_name)
            : (string) ($ticket->holder_name ?: $attendee?->full_name ?: $ticket->holder_name_bn ?: $attendee?->full_name_bn);
    }

    private function eventDate(): ?CarbonInterface
    {
        $value = $this->setting('event.date');

        if ($value === null) {
            return null;
        }

        try {
            return $this->inEventTimezone(Carbon::parse($value));
        } catch (Throwable) {
            return null;
        }
    }

    private function inEventTimezone(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()
            ->setTimezone((string) config('app.timezone'))
            ->locale(App::getLocale());
    }

    /**
     * The card follows the email it travels in — same channel, same
     * language — rather than the app locale of whichever worker draws it.
     */
    private function locale(): string
    {
        return (string) (config('notifications.locales.email') ?? config('notifications.locales.default', 'en'));
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
        return (string) __("share_card.{$key}", $replace);
    }

    private function digits(string $value): string
    {
        return BanglaNumerals::localise($value, App::getLocale());
    }

    /**
     * Through the catalogue, so a key nobody has saved yet answers with
     * its configured default — the same value the Settings screen shows —
     * rather than leaving the date off the card.
     */
    private function setting(string $key): ?string
    {
        $value = EventSettingCatalogue::resolve($key)?->value;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function toJpeg(string $png): string
    {
        $image = @imagecreatefromstring($png);

        if ($image === false) {
            throw new RuntimeException('Chrome produced a screenshot GD could not decode.');
        }

        // The card is fully opaque (the body paints its own background), so
        // nothing is lost dropping the alpha channel JPEG cannot carry.
        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        if ($jpeg === '') {
            throw new RuntimeException('GD produced an empty JPEG.');
        }

        return $jpeg;
    }
}
