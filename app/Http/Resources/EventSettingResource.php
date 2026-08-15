<?php

namespace App\Http\Resources;

use App\Domain\Shared\Models\EventSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventSetting
 */
class EventSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'value' => $this->value,
            'typed_value' => $this->typedValue(),
            // The declared type, so a client can render the right editor
            // instead of guessing from the JSON type of `typed_value` —
            // a `datetime` and a `string` are indistinguishable there.
            'type' => $this->type,
            'is_public' => $this->is_public,
            'label' => $this->label,
            'description' => $this->description,
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Only present when the caller eager-loaded the relation, which
            // the public endpoint deliberately does not — a staff member's
            // name must never reach an unauthenticated response.
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy?->name),
        ];
    }
}
