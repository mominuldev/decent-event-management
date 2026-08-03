<?php

namespace App\Http\Controllers\Api\Scanner;

use App\Domain\CheckIn\Actions\ProcessCheckIn;
use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scanner\StoreScanRequest;
use App\Http\Resources\CheckInResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class CheckInSyncController extends Controller
{
    public function __construct(
        private readonly ProcessCheckIn $processCheckIn
    ) {}

    public function store(StoreScanRequest $request): JsonResponse
    {
        /** @var array{gate_id: string, scans: array<int, array{client_scan_uuid: string, raw_payload: string, party_size: int, scanned_at: string, latitude?: float, longitude?: float}>} $validated */
        $validated = $request->validated();

        /** @var Gate $gate */
        $gate = Gate::findOrFail($validated['gate_id']);

        /** @var CheckInDevice|null $device */
        $device = $request->attributes->get('checkin_device');

        /** @var User|null $scannedBy */
        $scannedBy = $request->user();

        $results = [];

        foreach ($validated['scans'] as $scan) {
            $scannedAt = Carbon::parse($scan['scanned_at']);

            $checkIn = $this->processCheckIn->execute(
                clientScanUuid: $scan['client_scan_uuid'],
                rawPayload: $scan['raw_payload'],
                partySize: $scan['party_size'],
                gate: $gate,
                device: $device,
                scannedBy: $scannedBy,
                scannedAt: $scannedAt,
                latitude: $scan['latitude'] ?? null,
                longitude: $scan['longitude'] ?? null,
                isManualOverride: false
            );

            $results[] = $checkIn;
        }

        $latestVersion = (int) (Ticket::max('manifest_version') ?? 0);

        return response()->json([
            'results' => CheckInResource::collection($results),
            'manifest_version' => $latestVersion,
        ]);
    }
}
