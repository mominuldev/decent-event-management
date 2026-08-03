<?php

namespace App\Http\Resources;

use App\Domain\Registration\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Registration
 */
class RegistrationResource extends JsonResource
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
            'registration_number' => $this->registration_number,
            'status' => $this->status,
            'participation_type' => $this->participation_type,
            'adults_count' => $this->adults_count,
            'children_count' => $this->children_count,
            'subtotal_paisa' => $this->subtotal_paisa,
            'discount_paisa' => $this->discount_paisa,
            'total_paisa' => $this->total_paisa,
            'currency' => $this->currency,
            'discount_code' => $this->discount_code,
            'comments' => $this->comments,
            'special_notes' => $this->special_notes,
            'source' => $this->source,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'attendee' => new AttendeeResource($this->whenLoaded('attendee')),
            'ticket_type' => new TicketTypeResource($this->whenLoaded('ticketType')),
            'guests' => RegistrationGuestResource::collection($this->whenLoaded('guests')),
            'event_session' => new EventSessionResource($this->whenLoaded('eventSession')),
        ];
    }
}
