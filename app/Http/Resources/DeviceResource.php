<?php

namespace App\Http\Resources;

use App\Domain\CheckIn\Models\CheckInDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CheckInDevice
 */
class DeviceResource extends JsonResource
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
            'device_code' => $this->device_code,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,
            'status' => $this->status,
            'enrolled_at' => $this->enrolled_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'manifest_version' => $this->manifest_version,
            'last_sync_at' => $this->last_sync_at?->toISOString(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'pending_scan_count' => $this->pending_scan_count,
            'battery_level' => $this->battery_level,
            'total_scans' => $this->total_scans,
            'volunteer' => $this->whenLoaded('volunteerProfile', fn () => $this->volunteerProfile === null ? null : [
                'ulid' => $this->volunteerProfile->ulid,
                'volunteer_code' => $this->volunteerProfile->volunteer_code,
                'name' => $this->volunteerProfile->user?->name,
            ]),
        ];
    }
}
