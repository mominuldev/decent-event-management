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
 * sequences instead of the correct ligature). FreeSerif is mpdf's own
 * verified choice for Indic scripts (see vendor mpdf-examples
 * example32_indic.php) and needs no custom font registration.
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
