<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\ContentPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for the CMS pages table — enough to sort, filter and see
 * workflow state, without loading every page's block tree.
 *
 * @mixin ContentPage
 */
class AdminContentPageSummaryResource extends JsonResource
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
            'status' => $this->status,
            'is_live' => $this->isLive(),
            'published_at' => $this->published_at?->toISOString(),
            'is_indexable' => $this->is_indexable,
            'position' => $this->position,
            'revision_number' => $this->revision_number,
            'has_preview_token' => $this->preview_token !== null,
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->name),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
