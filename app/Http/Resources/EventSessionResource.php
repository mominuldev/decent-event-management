<?php

namespace App\Http\Resources;

use App\Domain\CheckIn\Models\EventSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventSession
 */
class EventSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'venue' => $this->venue,
            'starts_at' => $this->starts_at->toISOString(),
            'ends_at' => $this->ends_at->toISOString(),
            'checkin_opens_at' => $this->checkin_opens_at->toISOString(),
            'checkin_closes_at' => $this->checkin_closes_at->toISOString(),
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
        ];
    }
}
