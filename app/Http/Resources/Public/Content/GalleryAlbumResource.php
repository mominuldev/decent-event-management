<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\GalleryAlbum;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GalleryAlbum
 */
class GalleryAlbumResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'slug' => $this->slug,
            'title' => $this->localised($request, $this->title, $this->title_bn),
            'description' => $this->localised($request, $this->description, $this->description_bn),
            'cover' => $this->whenLoaded('cover', fn () => $this->cover === null ? null : PublicMediaResource::make($this->cover)),
            'position' => $this->position,
            'items' => GalleryItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
