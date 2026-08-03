<?php

namespace App\Domain\CheckIn\Actions;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Services\AdmissionPolicy;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Support\Carbon;

class ProcessCheckIn
{
    public function __construct(
        private readonly AdmissionPolicy $policy
    ) {}

    public function execute(
        string $clientScanUuid,
        string $rawPayload,
        int $partySize,
        Gate $gate,
        ?CheckInDevice $device = null,
        ?User $scannedBy = null,
        ?Carbon $scannedAt = null,
        ?float $latitude = null,
        ?float $longitude = null,
        bool $isManualOverride = false
    ): CheckIn {
        /** @var CheckIn|null $existing */
        $existing = CheckIn::where('client_scan_uuid', $clientScanUuid)->first();
        if ($existing !== null) {
            return $existing;
        }

        $scannedAt = $scannedAt ?? now();

        $parts = explode('.', $rawPayload);
        if (count($parts) !== 6 || $parts[0] !== 'DTM1') {
            return $this->createCheckIn(
                clientScanUuid: $clientScanUuid,
                rawPayload: $rawPayload,
                gate: $gate,
                result: 'invalid_format',
                admittedCount: 0,
                device: $device,
                scannedBy: $scannedBy,
                scannedAt: $scannedAt,
                latitude: $latitude,
                longitude: $longitude,
                isManualOverride: $isManualOverride,
                ticket: null,
                rejectionDetail: 'Invalid QR code format'
            );
        }

        $ticketUlid = $parts[1];

        /** @var Ticket|null $ticket */
        $ticket = Ticket::where('ulid', $ticketUlid)->first();

        if ($ticket === null) {
            return $this->createCheckIn(
                clientScanUuid: $clientScanUuid,
                rawPayload: $rawPayload,
                gate: $gate,
                result: 'revoked',
                admittedCount: 0,
                device: $device,
                scannedBy: $scannedBy,
                scannedAt: $scannedAt,
                latitude: $latitude,
                longitude: $longitude,
                isManualOverride: $isManualOverride,
                ticket: null
            );
        }

        $policyResult = $this->policy->evaluate($ticket, $gate, $partySize);

        if ($policyResult !== 'admitted' && ! $isManualOverride) {
            return $this->createCheckIn(
                clientScanUuid: $clientScanUuid,
                rawPayload: $rawPayload,
                gate: $gate,
                result: $policyResult,
                admittedCount: 0,
                device: $device,
                scannedBy: $scannedBy,
                scannedAt: $scannedAt,
                latitude: $latitude,
                longitude: $longitude,
                isManualOverride: false,
                ticket: $ticket
            );
        }

        $affected = $ticket->tryAdmit($partySize);

        if ($affected) {
            $gate->increment('admitted_count', $partySize);
            if ($gate->event_session_id !== null) {
                $gate->eventSession()->increment('admitted_count', $partySize);
            }

            return $this->createCheckIn(
                clientScanUuid: $clientScanUuid,
                rawPayload: $rawPayload,
                gate: $gate,
                result: $isManualOverride ? 'manual_override' : 'admitted',
                admittedCount: $partySize,
                device: $device,
                scannedBy: $scannedBy,
                scannedAt: $scannedAt,
                latitude: $latitude,
                longitude: $longitude,
                isManualOverride: $isManualOverride,
                ticket: $ticket,
                rejectionDetail: null,
                scanMode: 'online',
                overrideReason: $isManualOverride ? 'Manual override by operator' : null,
                overrideByUserId: $isManualOverride ? $scannedBy?->id : null
            );
        }

        return $this->createCheckIn(
            clientScanUuid: $clientScanUuid,
            rawPayload: $rawPayload,
            gate: $gate,
            result: 'duplicate',
            admittedCount: 0,
            device: $device,
            scannedBy: $scannedBy,
            scannedAt: $scannedAt,
            latitude: $latitude,
            longitude: $longitude,
            isManualOverride: $isManualOverride,
            ticket: $ticket,
            rejectionDetail: 'Atomic update failed; ticket already fully admitted at another gate'
        );
    }

    private function createCheckIn(
        string $clientScanUuid,
        string $rawPayload,
        Gate $gate,
        string $result,
        int $admittedCount,
        ?CheckInDevice $device,
        ?User $scannedBy,
        Carbon $scannedAt,
        ?float $latitude,
        ?float $longitude,
        bool $isManualOverride,
        ?Ticket $ticket,
        ?string $rejectionDetail = null,
        string $scanMode = 'online',
        ?string $overrideReason = null,
        ?int $overrideByUserId = null
    ): CheckIn {
        /** @var CheckIn $checkIn */
        $checkIn = CheckIn::create([
            'client_scan_uuid' => $clientScanUuid,
            'ticket_id' => $ticket?->id,
            'registration_id' => $ticket?->registration_id,
            'attendee_id' => $ticket?->attendee_id,
            'event_session_id' => $gate->event_session_id,
            'gate_id' => $gate->id,
            'device_id' => $device?->id,
            'scanned_by_user_id' => $scannedBy?->id,
            'result' => $result,
            'rejection_detail' => $rejectionDetail,
            'admitted_count' => $admittedCount,
            'raw_payload' => $rawPayload,
            'signature_valid' => true,
            'scan_mode' => $scanMode,
            'is_manual_override' => $isManualOverride,
            'override_by_user_id' => $overrideByUserId,
            'override_reason' => $overrideReason,
            'scanned_at' => $scannedAt,
            'synced_at' => now(),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        return $checkIn;
    }
}
