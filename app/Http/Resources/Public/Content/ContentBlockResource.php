<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Support\ContentLocale;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentBlock
 */
class ContentBlockResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'type' => $this->type,
            'position' => $this->position,
            // Per-key fallback: a block the editor has only half-translated
            // keeps its English values for the untranslated keys.
            'data' => ContentLocale::pickArray($this->contentLocale($request), $this->data, $this->data_bn),
            'media' => $this->whenLoaded('media', fn () => $this->media === null ? null : PublicMediaResource::make($this->media)),
        ];
    }
}
