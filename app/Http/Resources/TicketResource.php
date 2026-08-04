<?php

namespace App\Http\Resources;

use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 */
class TicketResource extends JsonResource
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
            'ticket_number' => $this->ticket_number,
            'status' => $this->status,
            'admits_total' => $this->admits_total,
            'admitted_count' => $this->admitted_count,
            'price_paid_paisa' => $this->price_paid_paisa,
            'currency' => $this->currency,
            'holder_name' => $this->holder_name,
            'holder_batch_year' => $this->holder_batch_year,
            'holder_type_label' => $this->holder_type_label,
            'issued_at' => $this->issued_at?->toISOString(),
            'voided_at' => $this->voided_at?->toISOString(),
            'void_reason' => $this->void_reason,
            'first_admitted_at' => $this->first_admitted_at?->toISOString(),
            'last_admitted_at' => $this->last_admitted_at?->toISOString(),
            'manifest_version' => $this->manifest_version,
            'created_at' => $this->created_at?->toISOString(),
            'ticket_type' => new TicketTypeResource($this->whenLoaded('ticketType')),
            'qr_code_payload' => $this->whenLoaded('qrCode', fn () => $this->qrCode?->payload),
            'qr_code_image_url' => $this->whenLoaded('qrCode', fn () => $this->qrCode?->image?->temporarySignedUrl()),
            'replaces' => new TicketResource($this->whenLoaded('replaces')),
        ];
    }
}
