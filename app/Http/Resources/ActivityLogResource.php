<?php

namespace App\Http\Resources;

use App\Domain\Shared\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
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
            'log_name' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'properties' => $this->properties,
            'severity' => $this->severity,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
