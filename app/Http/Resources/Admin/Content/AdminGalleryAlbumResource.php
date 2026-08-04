<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\GalleryAlbum;
use App\Http\Resources\MediaFileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GalleryAlbum
 */
class AdminGalleryAlbumResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'slug' => $this->slug,
            'title' => $this->title,
            'title_bn' => $this->title_bn,
            'description' => $this->description,
            'description_bn' => $this->description_bn,
            'cover' => $this->whenLoaded('cover', fn () => $this->cover === null ? null : MediaFileResource::make($this->cover)),
            'position' => $this->position,
            'is_published' => $this->is_published,
            'items_count' => $this->whenCounted('items'),
            'items' => AdminGalleryItemResource::collection($this->whenLoaded('items')),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
