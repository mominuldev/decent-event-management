<?php

namespace App\Http\Resources;

use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketType
 */
class TicketTypeResource extends JsonResource
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
            'name_bn' => $this->name_bn,
            'description' => $this->description,
            'base_price_paisa' => $this->base_price_paisa,
            'additional_adult_price_paisa' => $this->additional_adult_price_paisa,
            'additional_child_price_paisa' => $this->additional_child_price_paisa,
            'currency' => $this->currency,
            'base_admits' => $this->base_admits,
            'max_admits' => $this->max_admits,
            'child_free_under_age' => $this->child_free_under_age,
            'allowed_participant_types' => $this->allowed_participant_types,
            'quantity_total' => $this->quantity_total,
            'quantity_sold' => $this->quantity_sold,
            'quantity_reserved' => $this->quantity_reserved,
            'quantity_available' => max(0, $this->quantity_total - $this->quantity_sold - $this->quantity_reserved),
            'requires_approval' => $this->requires_approval,
            'includes_tshirt' => $this->includes_tshirt,
            'includes_meal' => $this->includes_meal,
            'sale_starts_at' => $this->sale_starts_at?->toISOString(),
            'sale_ends_at' => $this->sale_ends_at?->toISOString(),
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'badge_color' => $this->badge_color,
            'sort_order' => $this->sort_order,
        ];
    }
}
