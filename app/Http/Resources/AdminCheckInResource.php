<?php

namespace App\Http\Resources;

use App\Domain\CheckIn\Models\CheckIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing check-in detail — deliberately separate from CheckInResource,
 * which is also returned by the scanner-guard sync endpoint and must never
 * gain admin-only fields (override reason, conflict resolution actor).
 *
 * @mixin CheckIn
 */
class AdminCheckInResource extends JsonResource
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
            'rejection_detail' => $this->rejection_detail,
            'admitted_count' => $this->admitted_count,
            'scan_mode' => $this->scan_mode,
            'is_manual_override' => $this->is_manual_override,
            'override_reason' => $this->override_reason,
            'override_by' => $this->whenLoaded('overrideBy', fn () => $this->overrideBy?->name),
            'conflict_flag' => $this->conflict_flag,
            'conflict_resolved_at' => $this->conflict_resolved_at?->toISOString(),
            'conflict_resolved_by' => $this->whenLoaded('conflictResolvedBy', fn () => $this->conflictResolvedBy?->name),
            'scanned_at' => $this->scanned_at->toISOString(),
            'synced_at' => $this->synced_at?->toISOString(),
            'device_clock_skew_ms' => $this->device_clock_skew_ms,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'gate' => $this->whenLoaded('gate', fn () => [
                'ulid' => $this->gate?->ulid,
                'code' => $this->gate?->code,
                'name' => $this->gate?->name,
            ]),
            'ticket' => $this->whenLoaded('ticket', fn () => $this->ticket === null ? null : [
                'ulid' => $this->ticket->ulid,
                'ticket_number' => $this->ticket->ticket_number,
            ]),
            'registration' => $this->whenLoaded('registration', fn () => $this->registration === null ? null : [
                'ulid' => $this->registration->ulid,
                'registration_number' => $this->registration->registration_number,
            ]),
            'attendee' => $this->whenLoaded('attendee', fn () => $this->attendee === null ? null : [
                'ulid' => $this->attendee->ulid,
                'full_name' => $this->attendee->full_name,
            ]),
            'device' => $this->whenLoaded('device', fn () => $this->device === null ? null : [
                'ulid' => $this->device->ulid,
                'device_code' => $this->device->device_code,
                'device_name' => $this->device->device_name,
            ]),
            'scanned_by' => $this->whenLoaded('scannedBy', fn () => $this->scannedBy?->name),
        ];
    }
}
