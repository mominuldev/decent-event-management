<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\ContentPage;
use App\Http\Resources\MediaFileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The editorial view of a page — both locales, workflow state, and who
 * touched it last.
 *
 * `preview_token` is never included, even here: it is a bearer secret for the
 * public read API, and it is returned exactly once, by the endpoint that
 * mints it. `has_preview_token` is enough for the UI to know whether a share
 * link exists.
 *
 * @mixin ContentPage
 */
class AdminContentPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'slug' => $this->slug,
            'template' => $this->template,
            'title' => $this->title,
            'title_bn' => $this->title_bn,
            'excerpt' => $this->excerpt,
            'excerpt_bn' => $this->excerpt_bn,
            'seo_title' => $this->seo_title,
            'seo_title_bn' => $this->seo_title_bn,
            'seo_description' => $this->seo_description,
            'seo_description_bn' => $this->seo_description_bn,
            'og_image' => $this->whenLoaded('ogImage', fn () => $this->ogImage === null ? null : MediaFileResource::make($this->ogImage)),
            'status' => $this->status,
            'is_live' => $this->isLive(),
            'published_at' => $this->published_at?->toISOString(),
            'is_indexable' => $this->is_indexable,
            'position' => $this->position,
            'revision_number' => $this->revision_number,
            'has_preview_token' => $this->preview_token !== null,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->name),
            'published_by' => $this->whenLoaded('publishedBy', fn () => $this->publishedBy?->name),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'blocks' => AdminContentBlockResource::collection($this->whenLoaded('blocks')),
        ];
    }
}
