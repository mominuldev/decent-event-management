<?php

namespace App\Http\Resources;

use App\Domain\CheckIn\Models\VolunteerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VolunteerProfile
 */
class VolunteerResource extends JsonResource
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
            'volunteer_code' => $this->volunteer_code,
            'team' => $this->team,
            'shift_starts_at' => $this->shift_starts_at?->toISOString(),
            'shift_ends_at' => $this->shift_ends_at?->toISOString(),
            'is_active' => $this->is_active,
            'revoked_at' => $this->revoked_at?->toISOString(),
            'total_scans' => $this->total_scans,
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'ulid' => $this->user->ulid,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ]),
            'gate_assignments' => $this->whenLoaded('gateAssignments', fn () => $this->gateAssignments->map(fn ($assignment) => [
                'gate' => $assignment->gate === null ? null : [
                    'ulid' => $assignment->gate->ulid,
                    'code' => $assignment->gate->code,
                    'name' => $assignment->gate->name,
                ],
                'event_session_ulid' => $assignment->eventSession?->ulid,
            ])),
        ];
    }
}
