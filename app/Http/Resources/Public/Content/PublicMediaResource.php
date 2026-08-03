<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Shared\Models\MediaFile;
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
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'url' => $this->publicUrl(),
            'mime_type' => $this->mime_type,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
