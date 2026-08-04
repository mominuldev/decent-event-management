<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\ScheduleItem;
use App\Http\Resources\MediaFileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduleItem
 */
class AdminScheduleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'title' => $this->title,
            'title_bn' => $this->title_bn,
            'description' => $this->description,
            'description_bn' => $this->description_bn,
            'speaker_name' => $this->speaker_name,
            'speaker_name_bn' => $this->speaker_name_bn,
            'speaker_title' => $this->speaker_title,
            'speaker_title_bn' => $this->speaker_title_bn,
            'speaker_photo' => $this->whenLoaded('speakerPhoto', fn () => $this->speakerPhoto === null ? null : MediaFileResource::make($this->speakerPhoto)),
            'venue' => $this->venue,
            'venue_bn' => $this->venue_bn,
            'track' => $this->track,
            'starts_at' => $this->starts_at->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            // A soft reference to CheckIn's event_sessions.code, never a
            // foreign key — see the model note on the module boundary.
            'event_session_code' => $this->event_session_code,
            'position' => $this->position,
            'is_published' => $this->is_published,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
