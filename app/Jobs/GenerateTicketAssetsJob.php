<?php

namespace App\Jobs;

use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\GenerateTicketPdf;
use App\Domain\Ticketing\Services\RenderTicketQrImage;
use App\Domain\Ticketing\Services\TicketAssetStore;
use App\Domain\Ticketing\Services\TicketShareCard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The first job dispatched on the `tickets` Horizon lane (docs/08 Phase
 * 6). Renders the QR PNG, the bilingual A5 PDF and the "আমি থাকছি!" share
 * card for a freshly-issued ticket and stores all three as private
 * `media_files` rows — kept off the request/transaction path per the
 * architecture rule that PDF/QR rendering is async work (CLAUDE.md
 * "Layering within a module").
 *
 * Idempotent by construction: every part no-ops once its media id is
 * already set, so a retry or an accidental double-dispatch never creates
 * duplicate media rows.
 */
class GenerateTicketAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $ticketId)
    {
        $this->onQueue('tickets');
    }

    public function handle(
        RenderTicketQrImage $qrImageRenderer,
        GenerateTicketPdf $pdfRenderer,
        TicketAssetStore $store,
        TicketShareCard $shareCard,
    ): void {
        $ticket = Ticket::find($this->ticketId);

        if ($ticket === null) {
            return;
        }

        $ticket->loadMissing('qrCode');
        $qrCode = $ticket->qrCode;

        if ($qrCode !== null && $qrCode->image_media_id === null) {
            $png = $qrImageRenderer->render($qrCode->payload);
            $media = $store->put(
                binary: $png,
                collection: 'ticket_qr',
                mimeType: 'image/png',
                extension: 'png',
                originalName: "{$ticket->ticket_number}-qr.png",
            );
            // `image_media_id` is deliberately outside $fillable (nothing
            // else should mass-assign it) — this is the one system path
            // allowed to set it.
            $qrCode->forceFill(['image_media_id' => $media->id])->save();
        }

        if ($ticket->pdf_media_id === null) {
            $pdf = $pdfRenderer->render($ticket);
            $media = $store->put(
                binary: $pdf,
                collection: 'ticket_pdf',
                mimeType: 'application/pdf',
                extension: 'pdf',
                originalName: "{$ticket->ticket_number}.pdf",
            );
            // Same as above — `pdf_media_id` is outside $fillable to keep
            // it out of reach of any request-driven ticket write.
            $ticket->forceFill(['pdf_media_id' => $media->id])->save();
        }

        // The confirmation email may already have drawn and stored this
        // while the job was queued; `ensureStored()` is what makes the two
        // paths agree on one row.
        $shareCard->ensureStored($ticket);
    }
}
