<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\Sponsor;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sponsor
 */
class SponsorResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->localised($request, $this->name, $this->name_bn),
            'tier' => $this->tier,
            'website_url' => $this->website_url,
            'description' => $this->localised($request, $this->description, $this->description_bn),
            'logo' => $this->whenLoaded('logo', fn () => $this->logo === null ? null : PublicMediaResource::make($this->logo)),
            'position' => $this->position,
        ];
    }
}
