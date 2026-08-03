<?php

namespace App\Http\Resources;

use App\Domain\Registration\Models\RegistrationGuest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RegistrationGuest
 */
class RegistrationGuestResource extends JsonResource
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
            'full_name' => $this->full_name,
            'relation' => $this->relation,
            'age_group' => $this->age_group,
            'age' => $this->age,
            'gender' => $this->gender,
            'tshirt_required' => $this->tshirt_required,
            'tshirt_size' => $this->tshirt_size,
            'sort_order' => $this->sort_order,
        ];
    }
}
