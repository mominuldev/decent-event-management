<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\ScheduleItem;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduleItem
 */
class ScheduleItemResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'title' => $this->localised($request, $this->title, $this->title_bn),
            'description' => $this->localised($request, $this->description, $this->description_bn),
            'speaker_name' => $this->localised($request, $this->speaker_name, $this->speaker_name_bn),
            'speaker_title' => $this->localised($request, $this->speaker_title, $this->speaker_title_bn),
            'speaker_photo' => $this->whenLoaded('speakerPhoto', fn () => $this->speakerPhoto === null ? null : PublicMediaResource::make($this->speakerPhoto)),
            'venue' => $this->localised($request, $this->venue, $this->venue_bn),
            'track' => $this->track,
            // `starts_at` is NOT NULL on the table; only `ends_at` is optional.
            'starts_at' => $this->starts_at->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'position' => $this->position,
        ];
    }
}
