<?php

namespace App\Http\Resources;

use App\Domain\Registration\Support\PartySize;
use App\Domain\Registration\Support\RegistrationWindow;
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
            'base_price_tk' => $this->base_price_tk,
            'additional_adult_price_tk' => $this->additional_adult_price_tk,
            'additional_child_price_tk' => $this->additional_child_price_tk,
            'current_student_price_tk' => $this->current_student_price_tk,
            'currency' => $this->currency,
            'base_admits' => $this->base_admits,
            'max_admits' => $this->max_admits,
            'allows_family' => $this->allows_family,
            // The limit a registration is actually held to — the event-wide
            // `registration.max_family_size` setting when `allows_family` is
            // on, otherwise 1. Published so the public form offers exactly
            // as many member rows as CreateRegistration will accept; it must
            // not read `max_admits`, which no longer bounds the party.
            'max_party_size' => PartySize::limitFor($this->resource),
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
            // The window a registration on this type is actually accepted
            // in — the event-wide `registration.opens_at` / `closes_at`
            // settings narrowed by the sale dates above. Published for the
            // same reason as `max_party_size`: the public site gates its
            // form on exactly what CreateRegistration will enforce, rather
            // than re-deriving it from a settings fetch of its own.
            'registration_opens_at' => RegistrationWindow::opensFor($this->resource)?->toISOString(),
            'registration_closes_at' => RegistrationWindow::closesFor($this->resource)?->toISOString(),
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'badge_color' => $this->badge_color,
            'sort_order' => $this->sort_order,
        ];
    }
}
