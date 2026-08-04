<?php

namespace App\Jobs;

use App\Domain\Shared\Models\MediaFile;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\GenerateTicketPdf;
use App\Domain\Ticketing\Services\RenderTicketQrImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The first job dispatched on the `tickets` Horizon lane (docs/08 Phase
 * 6). Renders the QR PNG and the bilingual A5 PDF for a freshly-issued
 * ticket and stores both as private `media_files` rows — kept off the
 * request/transaction path per the architecture rule that PDF/QR
 * rendering is async work (CLAUDE.md "Layering within a module").
 *
 * Idempotent by construction: both halves no-op once their media id is
 * already set, so a retry or an accidental double-dispatch never creates
 * duplicate media rows.
 */
class GenerateTicketAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const string DISK = 'local';

    public int $tries = 3;

    public function __construct(public readonly int $ticketId)
    {
        $this->onQueue('tickets');
    }

    public function handle(RenderTicketQrImage $qrImageRenderer, GenerateTicketPdf $pdfRenderer): void
    {
        $ticket = Ticket::find($this->ticketId);

        if ($ticket === null) {
            return;
        }

        $ticket->loadMissing('qrCode');
        $qrCode = $ticket->qrCode;

        if ($qrCode !== null && $qrCode->image_media_id === null) {
            $png = $qrImageRenderer->render($qrCode->payload);
            $media = $this->storeMedia(
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
            $media = $this->storeMedia(
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
    }

    private function storeMedia(string $binary, string $collection, string $mimeType, string $extension, string $originalName): MediaFile
    {
        $path = "{$collection}/".Str::lower((string) Str::ulid()).".{$extension}";

        Storage::disk(self::DISK)->put($path, $binary);

        $size = getimagesizefromstring($binary);

        return MediaFile::create([
            'collection' => $collection,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => strlen($binary),
            'checksum_sha256' => hash('sha256', $binary),
            'width' => $size !== false ? $size[0] : null,
            'height' => $size !== false ? $size[1] : null,
            'is_public' => false,
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'uploaded_by_type' => 'system',
            'uploaded_by_id' => null,
        ]);
    }
}
