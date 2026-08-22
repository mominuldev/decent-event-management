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
        // A secret is write-only across the API boundary. Its value never
        // leaves the server — not to a Super Admin, not on the settings
        // screen, not in a page the browser might cache — so the client is
        // told only *that* one is stored and which one, via the last four
        // characters. Everything else about the row renders as normal.
        $isSecret = $this->isSecret();

        return [
            'key' => $this->key,
            'group' => $this->group,
            'value' => $isSecret ? null : $this->value,
            'typed_value' => $isSecret ? null : $this->typedValue(),
            'is_secret' => $isSecret,
            'is_set' => $isSecret ? $this->hasValue() : null,
            'masked_value' => $isSecret ? $this->maskedValue() : null,
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
