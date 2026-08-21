<?php

namespace App\Domain\Reporting\Support;

use Illuminate\Http\Response;

/**
 * A generated export, ready to hand to the browser: the bytes plus the two
 * pieces of metadata a download needs to be more than an "untitled" blob.
 *
 * Deliberately a value object rather than a Symfony response — the action
 * that builds an export should not have to know whether the caller wants to
 * stream it, store it as a MediaFile, or attach it to an email.
 */
final readonly class ExportedFile
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $contents,
    ) {}

    public function response(): Response
    {
        return new Response($this->contents, Response::HTTP_OK, [
            'Content-Type' => $this->mimeType,
            'Content-Length' => (string) strlen($this->contents),
            // Quoted because a filename may legitimately carry a space.
            'Content-Disposition' => 'attachment; filename="'.$this->filename.'"',
            // The payload is personal data assembled per-request; no shared
            // cache should ever hold a copy of it.
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
