<?php

namespace App\Http\Resources;

use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 */
class ManifestEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ticket_ulid' => $this->ulid,
            'ticket_number' => $this->ticket_number,
            'status' => $this->status,
            'admits_total' => $this->admits_total,
            'admitted_count' => $this->admitted_count,
            'holder_name' => $this->holder_name,
            'holder_batch_year' => $this->holder_batch_year,
            'holder_type_label' => $this->holder_type_label,
            'event_session_id' => $this->event_session_id,
            'ticket_type_id' => $this->ticket_type_id,
            'manifest_version' => $this->manifest_version,
        ];
    }
}
