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
            'label' => $this->label,
            'description' => $this->description,
        ];
    }
}
