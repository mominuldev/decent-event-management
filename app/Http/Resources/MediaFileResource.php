<?php

namespace App\Http\Resources;

use App\Domain\Shared\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file in the media library, as staff see it.
 *
 * `disk` and `path` are omitted on purpose: they describe our storage layout,
 * and a private file's URL must only ever be a short-TTL signed one. `url` is
 * therefore null for anything not explicitly public.
 *
 * @mixin MediaFile
 */
class MediaFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'collection' => $this->collection,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'is_public' => $this->is_public,
            'scan_status' => $this->scan_status,
            'url' => $this->publicUrl(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
