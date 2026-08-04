<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\Sponsor;
use App\Http\Resources\MediaFileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sponsor
 */
class AdminSponsorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'name_bn' => $this->name_bn,
            'tier' => $this->tier,
            'tier_rank' => $this->tierRank(),
            'logo' => $this->whenLoaded('logo', fn () => $this->logo === null ? null : MediaFileResource::make($this->logo)),
            'website_url' => $this->website_url,
            'description' => $this->description,
            'description_bn' => $this->description_bn,
            'position' => $this->position,
            'is_published' => $this->is_published,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
