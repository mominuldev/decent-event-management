<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\GalleryItem;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GalleryItem
 */
class GalleryItemResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'caption' => $this->localised($request, $this->caption, $this->caption_bn),
            'alt_text' => $this->localised($request, $this->alt_text, $this->alt_text_bn),
            'position' => $this->position,
            'media' => $this->whenLoaded('media', fn () => $this->media === null ? null : PublicMediaResource::make($this->media)),
        ];
    }
}
