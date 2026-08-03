<?php

namespace App\Http\Resources;

use App\Domain\CheckIn\Models\CheckIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CheckIn
 */
class CheckInResource extends JsonResource
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
            'result' => $this->result,
            'admitted_count' => $this->admitted_count,
            'scan_mode' => $this->scan_mode,
            'is_manual_override' => $this->is_manual_override,
            'scanned_at' => $this->scanned_at->toISOString(),
            'synced_at' => $this->synced_at?->toISOString(),
            'conflict_flag' => $this->conflict_flag,
            'device_clock_skew_ms' => $this->device_clock_skew_ms,
            'gate' => $this->whenLoaded('gate', fn () => [
                'code' => $this->gate?->code,
                'name' => $this->gate?->name,
            ]),
            'ticket' => $this->whenLoaded('ticket', fn () => [
                'ulid' => $this->ticket?->ulid,
                'ticket_number' => $this->ticket?->ticket_number,
            ]),
        ];
    }
}
