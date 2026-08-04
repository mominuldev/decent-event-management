<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\GalleryItem;
use App\Http\Resources\MediaFileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GalleryItem
 */
class AdminGalleryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'album_ulid' => $this->whenLoaded('album', fn () => $this->album?->ulid),
            'media' => $this->whenLoaded('media', fn () => $this->media === null ? null : MediaFileResource::make($this->media)),
            'caption' => $this->caption,
            'caption_bn' => $this->caption_bn,
            'alt_text' => $this->alt_text,
            'alt_text_bn' => $this->alt_text_bn,
            'position' => $this->position,
            'is_published' => $this->is_published,
        ];
    }
}
