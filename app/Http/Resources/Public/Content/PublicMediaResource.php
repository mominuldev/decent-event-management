<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Shared\Models\MediaFile;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The unauthenticated view of a media file. An explicit allowlist, not a
 * blocklist — `disk`, `path`, `checksum_sha256`, `scan_status` and the
 * uploader's identity are omitted rather than filtered.
 *
 * @mixin MediaFile
 */
class PublicMediaResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'url' => $this->publicUrl(),
            // Localised, with the English fallback every other CMS string gets.
            // A renderer that has a better alt of its own (a sponsor's name
            // under its logo) should still prefer that; this is the fallback
            // for an image that is otherwise nameless.
            'alt' => $this->localised($request, $this->alt_text, $this->alt_text_bn),
            'mime_type' => $this->mime_type,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
