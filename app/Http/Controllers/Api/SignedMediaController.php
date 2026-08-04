<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Models\MediaFile;
use App\Http\Controllers\Api\Attendee\TicketController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private media (ticket PDFs, QR images) via a short-TTL signed
 * URL — docs/06 §6.4: private by default, `Content-Disposition: attachment`,
 * `X-Content-Type-Options: nosniff`. The `signed` route middleware is the
 * only authorization check here; the policy check happens once, up front,
 * when the issuing endpoint mints the URL (e.g.
 * {@see TicketController::downloadPdf()}).
 */
class SignedMediaController extends Controller
{
    public function show(MediaFile $mediaFile): BinaryFileResponse|StreamedResponse
    {
        abort_unless(Storage::disk($mediaFile->disk)->exists($mediaFile->path), Response::HTTP_NOT_FOUND);

        return Storage::disk($mediaFile->disk)->download(
            $mediaFile->path,
            $mediaFile->original_name ?? basename($mediaFile->path),
            ['X-Content-Type-Options' => 'nosniff']
        );
    }
}
