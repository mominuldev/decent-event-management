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
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Scanner')]
class CheckInSyncController extends Controller
{
    public function __construct(
        private readonly ProcessCheckIn $processCheckIn
    ) {}

    #[OAT\Post(
        path: '/scanner/v1/scans',
        summary: 'Upload a batch of offline scans for admission processing (idempotent)',
        tags: ['Scanner'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'gate_id', type: 'string', description: 'ID of the gate this device is assigned to', required: ['gate_id']),
                        new OAT\Property(
                            property: 'scans',
                            type: 'array',
                            description: 'Batch of up to 100 scans captured while offline',
                            items: new OAT\Items(
                                properties: [
                                    new OAT\Property(
                                        property: 'client_scan_uuid',
                                        type: 'string',
                                        format: 'uuid',
                                        description: 'Client-generated UUID; a retried upload with the same UUID produces one check-in',
                                        required: ['client_scan_uuid']
                                    ),
                                    new OAT\Property(property: 'raw_payload', type: 'string', maxLength: 500, description: 'Raw signed QR payload as scanned', required: ['raw_payload']),
                                    new OAT\Property(property: 'party_size', type: 'integer', minimum: 1, maximum: 20, required: ['party_size']),
                                    new OAT\Property(property: 'scanned_at', type: 'string', format: 'date-time', required: ['scanned_at']),
                                    new OAT\Property(property: 'latitude', type: 'number', format: 'float'),
                                    new OAT\Property(property: 'longitude', type: 'number', format: 'float'),
                                ],
                                type: 'object'
                            ),
                            required: ['scans']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Per-scan admission results, in the same order as the submitted batch',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'results',
                                type: 'array',
                                items: new OAT\Items(
                                    properties: [
                                        new OAT\Property(property: 'ulid', type: 'string'),
                                        new OAT\Property(
                                            property: 'result',
                                            type: 'string',
                                            enum: ['admitted', 'manual_override', 'duplicate', 'invalid_format', 'invalid_signature', 'revoked', 'unpaid', 'expired', 'wrong_gate', 'wrong_session', 'over_capacity']
                                        ),
                                        new OAT\Property(property: 'admitted_count', type: 'integer'),
                                        new OAT\Property(property: 'scan_mode', type: 'string'),
                                        new OAT\Property(property: 'is_manual_override', type: 'boolean'),
                                        new OAT\Property(property: 'scanned_at', type: 'string', format: 'date-time'),
                                        new OAT\Property(property: 'synced_at', type: 'string', format: 'date-time'),
                                        new OAT\Property(property: 'conflict_flag', type: 'boolean'),
                                        new OAT\Property(property: 'device_clock_skew_ms', type: 'integer'),
                                    ],
                                    type: 'object'
                                )
                            ),
                            new OAT\Property(property: 'manifest_version', type: 'integer', description: 'Latest manifest version, so the device can detect it needs to refetch /scanner/v1/manifest'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Missing or invalid scanner token'),
            new OAT\Response(response: 422, description: 'Validation error, unknown gate, or gate not assigned to this device'),
        ]
    )]
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
