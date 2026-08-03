<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\ContentPage;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The index view — enough to build a sitemap or a link list without pulling
 * every block of every page.
 *
 * @mixin ContentPage
 */
class ContentPageSummaryResource extends JsonResource
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
            'is_indexable' => $this->is_indexable,
            'published_at' => $this->published_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
