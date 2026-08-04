<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\ContentPageRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a page's history. `blocks_snapshot` is included so the UI can
 * diff or preview a revision without a second round trip — it stores media by
 * ULID, never by internal id.
 *
 * @mixin ContentPageRevision
 */
class ContentPageRevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'revision_number' => $this->revision_number,
            'title' => $this->title,
            'title_bn' => $this->title_bn,
            'excerpt' => $this->excerpt,
            'excerpt_bn' => $this->excerpt_bn,
            'seo_title' => $this->seo_title,
            'seo_title_bn' => $this->seo_title_bn,
            'seo_description' => $this->seo_description,
            'seo_description_bn' => $this->seo_description_bn,
            'blocks_snapshot' => $this->blocks_snapshot ?? [],
            'status_at_capture' => $this->status_at_capture,
            'change_note' => $this->change_note,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
