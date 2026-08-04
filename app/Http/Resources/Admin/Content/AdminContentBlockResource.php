<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\ContentBlock;
use App\Http\Resources\MediaFileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A block as the editor sees it: both language halves side by side, and the
 * hidden ones included — the public resource shows one resolved locale and
 * only visible blocks.
 *
 * @mixin ContentBlock
 */
class AdminContentBlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'type' => $this->type,
            'position' => $this->position,
            'data' => $this->data ?? [],
            'data_bn' => $this->data_bn,
            'media' => $this->whenLoaded('media', fn () => $this->media === null ? null : MediaFileResource::make($this->media)),
            'is_visible' => $this->is_visible,
        ];
    }
}
