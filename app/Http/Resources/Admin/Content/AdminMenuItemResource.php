<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `resolved_url` is what the public site would actually render — null when
 * the item points at a page that is no longer live, which is exactly the
 * broken-link case the editor needs to see.
 *
 * @mixin MenuItem
 */
class AdminMenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'label' => $this->label,
            'label_bn' => $this->label_bn,
            'page_ulid' => $this->whenLoaded('page', fn () => $this->page?->ulid),
            'page_title' => $this->whenLoaded('page', fn () => $this->page?->title),
            'url' => $this->url,
            'resolved_url' => $this->resolvedUrl(),
            'target' => $this->target,
            'position' => $this->position,
            'is_visible' => $this->is_visible,
        ];
    }
}
