<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\ContentPage;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A full page with its block tree. `preview_token`, `status`, and the editor
 * user references are deliberately absent — this is the unauthenticated view.
 *
 * @mixin ContentPage
 */
class ContentPageResource extends JsonResource
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
            'template' => $this->template,
            'locale' => $this->contentLocale($request),
            'title' => $this->localised($request, $this->title, $this->title_bn),
            'excerpt' => $this->localised($request, $this->excerpt, $this->excerpt_bn),
            'seo_title' => $this->localised($request, $this->seo_title, $this->seo_title_bn),
            'seo_description' => $this->localised($request, $this->seo_description, $this->seo_description_bn),
            'og_image' => $this->whenLoaded('ogImage', fn () => $this->ogImage === null ? null : PublicMediaResource::make($this->ogImage)),
            'is_indexable' => $this->is_indexable,
            'published_at' => $this->published_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'blocks' => ContentBlockResource::collection($this->whenLoaded('blocks')),
        ];
    }
}
