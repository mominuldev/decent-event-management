<?php

namespace App\Http\Resources;

use App\Domain\Registration\Models\Attendee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attendee
 */
class AttendeeResource extends JsonResource
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
            'full_name_bn' => $this->full_name_bn,
            'father_name' => $this->father_name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toISOString(),
            'occupation' => $this->occupation,
            'designation' => $this->designation,
            'organization' => $this->organization,
            'participant_type' => $this->participant_type,
            'ssc_batch_year' => $this->ssc_batch_year,
            'current_class' => $this->current_class,
            'tshirt_required' => $this->tshirt_required,
            'tshirt_size' => $this->tshirt_size,
            'address_district' => $this->address_district,
            'current_address' => $this->current_address,
            'country' => $this->country,
            'blood_group' => $this->blood_group,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'notes' => $this->notes,
            'is_verified' => $this->is_verified,
            // Short-TTL signed URL (docs/06 §6.4), not the raw private-disk
            // path — `profilePhoto` lazy-loads here if a caller hasn't
            // eager-loaded it, which is fine for a single-resource response.
            'profile_photo_url' => $this->profilePhoto?->temporarySignedUrl(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
