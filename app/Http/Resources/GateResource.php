<?php

namespace App\Http\Resources;

use App\Domain\CheckIn\Models\Gate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Gate
 */
class GateResource extends JsonResource
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
            'allowed_ticket_type_ids' => $this->allowed_ticket_type_ids,
            'location_note' => $this->location_note,
            'is_active' => $this->is_active,
            'event_session' => new EventSessionResource($this->whenLoaded('eventSession')),
        ];
    }
}
