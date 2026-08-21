<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Services\HtmlToPdfRenderer;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Support\Facades\Storage;

/**
 * Bilingual (EN/Bangla) A5 ticket PDF, rendered by headless Chrome via
 * {@see HtmlToPdfRenderer}.
 *
 * It used to be mpdf, and the move was a correctness fix rather than a
 * preference. Two defects, both confirmed against this exact pipeline with
 * `pdftotext` and `hb-shape`, are closed by it:
 *
 *   1. mpdf assigns a synthetic private-use codepoint (TTFontFile's 0xE000
 *      fallback) to every glyph absent from the font's cmap — which is every
 *      Bengali conjunct ligature — and wrote that into the PDF's ToUnicode
 *      map. Extractors discard private-use codepoints, so the characters did
 *      not come out mangled, they came out MISSING: a real name like
 *      "মোহাম্মদ রহিম উদ্দিন" extracted as "মাহাদ রিহম উিন". Pre-base vowel
 *      signs additionally extracted in visual rather than logical order,
 *      which no ToUnicode map can express — that needs /ActualText, which
 *      mpdf cannot emit at all.
 *   2. mpdf's bundled FreeSerifBold.ttf has zero Bengali coverage, so bold
 *      Bangla did not degrade, it disappeared from the page. The template no
 *      longer needs the `.bn-value` opt-out that worked around it.
 *
 * Chrome shapes with HarfBuzz and writes a correct multi-character ToUnicode
 * map; every case round-trips. Fonts are bundled in resources/fonts rather
 * than taken from the host, so a ticket is identical on a developer's
 * machine and in the production image. See tests/Feature/Ticketing/
 * BanglaPdfTextLayerTest.php, which asserts the round trip directly.
 */
class GenerateTicketPdf
{
    public function __construct(
        private readonly RenderTicketQrImage $qrImage,
        private readonly HtmlToPdfRenderer $renderer,
    ) {}

    public function render(Ticket $ticket): string
    {
        $ticket->loadMissing(['ticketType', 'eventSession', 'attendee.profilePhoto', 'qrCode']);

        $payload = $ticket->qrCode?->payload;
        $qrDataUri = $payload !== null
            ? 'data:image/png;base64,'.base64_encode($this->qrImage->render($payload))
            : null;

        $session = $ticket->eventSession;
        $sessionWhen = $session?->starts_at !== null
            ? $session->starts_at->timezone(config('app.timezone'))->format('j M Y, g:i A')
            : null;

        $html = view('tickets.pdf', [
            'ticket' => $ticket,
            'eventName' => (string) (EventSetting::where('key', 'event.name')->value('value') ?? 'Event'),
            'ticketTypeName' => $ticket->ticketType->name ?? $ticket->holder_type_label,
            'sessionName' => $session?->name,
            'sessionWhen' => $sessionWhen,
            'sessionVenue' => $session?->venue,
            'photoDataUri' => $this->photoDataUri($ticket),
            'qrDataUri' => $qrDataUri,
            'fontFaceCss' => $this->renderer->fontFaceCss(),
            'title' => 'Ticket '.$ticket->ticket_number,
            'pageSize' => 'A5',
            'pageMargin' => '12mm',
        ])->render();

        return $this->renderer->render($html);
    }

    private function photoDataUri(Ticket $ticket): ?string
    {
        $photo = $ticket->attendee?->profilePhoto;

        if ($photo === null) {
            return null;
        }

        $binary = Storage::disk($photo->disk)->get($photo->path);

        if ($binary === null) {
            return null;
        }

        return "data:{$photo->mime_type};base64,".base64_encode($binary);
    }
}
