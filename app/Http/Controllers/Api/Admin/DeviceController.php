<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\Shared\Models\ActivityLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Devices')]
class DeviceController extends Controller
{
    #[OAT\Get(
        path: '/admin/devices',
        summary: 'List enrolled scanner devices',
        tags: ['Devices'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'status', in: 'query', description: 'Filter by device status', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'volunteer_ulid', in: 'query', description: 'Filter by assigned volunteer ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'per_page', in: 'query', description: 'Results per page, capped at 100', schema: new OAT\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated list of devices'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing device.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('device.view_any'), Response::HTTP_FORBIDDEN);

        $query = CheckInDevice::query()->with('volunteerProfile.user');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('volunteer_ulid')) {
            $volunteerUlid = (string) $request->input('volunteer_ulid');
            $query->whereHas('volunteerProfile', function ($q) use ($volunteerUlid): void {
                $q->where('ulid', $volunteerUlid);
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return DeviceResource::collection($query->paginate($perPage));
    }

    #[OAT\Get(
        path: '/admin/devices/{device}',
        summary: 'Get a single enrolled device by ULID',
        tags: ['Devices'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'device', description: 'Device ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Device details'),
            new OAT\Response(response: 403, description: 'Missing device.view_any permission'),
            new OAT\Response(response: 404, description: 'Device not found'),
        ]
    )]
    public function show(Request $request, CheckInDevice $device): DeviceResource
    {
        abort_unless((bool) $request->user()?->can('device.view_any'), Response::HTTP_FORBIDDEN);

        $device->load('volunteerProfile.user');

        return new DeviceResource($device);
    }

    #[OAT\Get(
        path: '/admin/devices/{device}/sync-status',
        summary: 'Get a device\'s offline-sync health',
        tags: ['Devices'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'device', description: 'Device ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Sync status detail',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'status', type: 'string'),
                            new OAT\Property(property: 'manifest_version', type: 'integer'),
                            new OAT\Property(property: 'last_sync_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'last_seen_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'pending_scan_count', type: 'integer'),
                            new OAT\Property(property: 'battery_level', type: 'integer', nullable: true),
                            new OAT\Property(property: 'total_scans', type: 'integer'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing device.view_sync_status permission'),
            new OAT\Response(response: 404, description: 'Device not found'),
        ]
    )]
    public function syncStatus(Request $request, CheckInDevice $device): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('device.view_sync_status'), Response::HTTP_FORBIDDEN);

        return response()->json([
            'status' => $device->status,
            'manifest_version' => $device->manifest_version,
            'last_sync_at' => $device->last_sync_at?->toISOString(),
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'pending_scan_count' => $device->pending_scan_count,
            'battery_level' => $device->battery_level,
            'total_scans' => $device->total_scans,
        ]);
    }

    #[OAT\Post(
        path: '/admin/devices/{device}/revoke',
        summary: 'Revoke an enrolled device',
        description: 'Locks the device out immediately (EnsureDeviceActive checks status on every scanner request) and deletes its bound Sanctum token as belt-and-braces.',
        tags: ['Devices'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'device', description: 'Device ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Device revoked'),
            new OAT\Response(response: 403, description: 'Missing device.revoke permission'),
            new OAT\Response(response: 404, description: 'Device not found'),
        ]
    )]
    public function revoke(Request $request, CheckInDevice $device): DeviceResource
    {
        abort_unless((bool) $request->user()?->can('device.revoke'), Response::HTTP_FORBIDDEN);

        $device->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        if ($device->sanctum_token_id !== null) {
            PersonalAccessToken::where('id', $device->sanctum_token_id)->delete();
        }

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'device',
            'event' => 'revoked',
            'description' => 'Device revoked',
            'causer_type' => $request->user()->getMorphClass(),
            'causer_id' => $request->user()->id,
            'subject_type' => $device->getMorphClass(),
            'subject_id' => $device->id,
            'properties' => ['device_ulid' => $device->ulid, 'device_code' => $device->device_code],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new DeviceResource($device->refresh());
    }
}
