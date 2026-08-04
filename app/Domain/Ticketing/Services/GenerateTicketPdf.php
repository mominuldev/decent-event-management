<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Shared\Models\EventSetting;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Bilingual (EN/Bangla) A5 ticket PDF. Uses mpdf's bundled `freeserif`
 * (GNU FreeFont, ttfonts/FreeSerif*.ttf) rather than a downloaded Noto
 * build — current Noto Sans Bengali releases use an OpenType GPOS lookup
 * (Type 5, Format 3) that mpdf's font engine can't parse, which silently
 * drops complex-script shaping (conjuncts render as broken base+virama
 * sequences instead of the correct ligature).
 *
 * Phase 8 finding, 2026-08-04, worse than the paragraph above previously
 * claimed: FreeSerif is *not* a fully verified Indic choice, on two counts
 * confirmed with `hb-shape` and `pdftotext` against this exact pipeline —
 *   1. FreeSerifBold.ttf has zero Bengali glyph coverage (every Bengali
 *      codepoint maps to .notdef). Any Bangla text rendered bold does not
 *      degrade, it disappears from the page entirely. resources/views/
 *      tickets/pdf.blade.php's `.bn-value` class works around this for the
 *      one dynamic Bangla field (holder_name_bn) by opting it out of the
 *      `.value` class's bold weight — any *other* template that puts
 *      Bangla text in a bold context needs the same treatment.
 *   2. Independent of bold: mpdf's built-in Bengali OTL/ligature engine
 *      does not emit a correct ToUnicode CMap entry for consonant-conjunct
 *      clusters (e.g. `দ্দ`) — the PDF's extractable text layer gets a
 *      private-use-area codepoint in place of the conjunct, not the real
 *      characters. Pre-base vowel signs (ি/ে/ৈ) also extract in visual
 *      rather than logical order. Visually the print may still look
 *      approximately right (unverified — physical print testing per
 *      docs/08 is still out of scope here); what's proven broken is
 *      text-layer fidelity: copy-paste, search, and accessibility tooling
 *      see garbage for any conjunct-bearing name, which covers a large
 *      share of real Bengali names. No fix is implemented for this —  it
 *      needs either a HarfBuzz-based pre-shaping pass feeding mpdf
 *      pre-shaped glyph runs, or a different PDF rendering engine
 *      entirely. Tracked, not silently dropped: see
 *      tests/Feature/Ticketing/GenerateTicketAssetsJobTest.php's Bangla
 *      test for what is and is not asserted as a result.
 */
class GenerateTicketPdf
{
    public function __construct(
        private readonly RenderTicketQrImage $qrImage,
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
        ])->render();

        $mpdf = new Mpdf(['format' => 'A5', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 12]);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
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
