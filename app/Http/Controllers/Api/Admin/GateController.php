<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\EventSession;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Ticketing\Models\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGateRequest;
use App\Http\Requests\Admin\UpdateGateRequest;
use App\Http\Resources\GateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Gates')]
class GateController extends Controller
{
    #[OAT\Get(
        path: '/admin/gates',
        summary: 'List gates',
        tags: ['Gates'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'event_session_ulid', in: 'query', description: 'Filter by event session ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'is_active', in: 'query', description: 'Filter by active status', schema: new OAT\Schema(type: 'boolean')),
            new OAT\Parameter(name: 'per_page', in: 'query', description: 'Results per page, capped at 100', schema: new OAT\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Paginated list of gates',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'array',
                                items: new OAT\Items(
                                    properties: [
                                        new OAT\Property(property: 'ulid', type: 'string'),
                                        new OAT\Property(property: 'code', type: 'string'),
                                        new OAT\Property(property: 'name', type: 'string'),
                                        new OAT\Property(property: 'allowed_ticket_type_ids', type: 'array', items: new OAT\Items(type: 'integer'), nullable: true),
                                        new OAT\Property(property: 'location_note', type: 'string', nullable: true),
                                        new OAT\Property(property: 'admitted_count', type: 'integer'),
                                        new OAT\Property(property: 'is_active', type: 'boolean'),
                                        new OAT\Property(property: 'event_session', type: 'object', nullable: true),
                                    ],
                                    type: 'object'
                                )
                            ),
                            new OAT\Property(property: 'links', type: 'object'),
                            new OAT\Property(property: 'meta', type: 'object'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing gate.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('gate.view_any'), Response::HTTP_FORBIDDEN);

        $query = Gate::query()->with('eventSession');

        if ($request->filled('event_session_ulid')) {
            $eventSessionUlid = (string) $request->input('event_session_ulid');
            $query->whereHas('eventSession', function ($q) use ($eventSessionUlid): void {
                $q->where('ulid', $eventSessionUlid);
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return GateResource::collection($query->paginate($perPage));
    }

    #[OAT\Post(
        path: '/admin/gates',
        summary: 'Create a new gate',
        tags: ['Gates'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'code', type: 'string', description: 'Unique short code, max 16 chars', required: ['code']),
                        new OAT\Property(property: 'name', type: 'string', required: ['name']),
                        new OAT\Property(property: 'event_session_ulid', type: 'string', nullable: true),
                        new OAT\Property(property: 'allowed_ticket_type_ulids', type: 'array', items: new OAT\Items(type: 'string'), nullable: true, description: 'If omitted, the gate admits every ticket type'),
                        new OAT\Property(property: 'location_note', type: 'string', nullable: true),
                        new OAT\Property(property: 'is_active', type: 'boolean', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Gate created'),
            new OAT\Response(response: 403, description: 'Missing gate.manage permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(StoreGateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $gate = Gate::create($this->resolveUlidReferences($validated));

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'gate',
            'event' => 'created',
            'description' => 'Gate created',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $gate->getMorphClass(),
            'subject_id' => $gate->id,
            'properties' => ['new' => $gate->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return (new GateResource($gate))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/gates/{gate}',
        summary: 'Get a single gate by ULID',
        tags: ['Gates'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'gate', description: 'Gate ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Gate details'),
            new OAT\Response(response: 403, description: 'Missing gate.view permission'),
            new OAT\Response(response: 404, description: 'Gate not found'),
        ]
    )]
    public function show(Request $request, Gate $gate): GateResource
    {
        abort_unless((bool) $request->user()?->can('gate.view'), Response::HTTP_FORBIDDEN);

        $gate->load('eventSession');

        return new GateResource($gate);
    }

    #[OAT\Patch(
        path: '/admin/gates/{gate}',
        summary: 'Update a gate',
        tags: ['Gates'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'gate', description: 'Gate ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'code', type: 'string'),
                        new OAT\Property(property: 'name', type: 'string'),
                        new OAT\Property(property: 'event_session_ulid', type: 'string', nullable: true),
                        new OAT\Property(property: 'allowed_ticket_type_ulids', type: 'array', items: new OAT\Items(type: 'string'), nullable: true),
                        new OAT\Property(property: 'location_note', type: 'string', nullable: true),
                        new OAT\Property(property: 'is_active', type: 'boolean', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Gate updated'),
            new OAT\Response(response: 403, description: 'Missing gate.manage permission'),
            new OAT\Response(response: 404, description: 'Gate not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(UpdateGateRequest $request, Gate $gate): GateResource
    {
        $oldData = $gate->toArray();
        $validated = $request->validated();

        $gate->update($this->resolveUlidReferences($validated));

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'gate',
            'event' => 'updated',
            'description' => 'Gate updated',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $gate->getMorphClass(),
            'subject_id' => $gate->id,
            'properties' => ['old' => $oldData, 'new' => $gate->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new GateResource($gate->refresh());
    }

    #[OAT\Delete(
        path: '/admin/gates/{gate}',
        summary: 'Delete a gate',
        description: 'Super-Admin-only. Cannot delete a gate that has any recorded check-ins.',
        tags: ['Gates'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'gate', description: 'Gate ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 204, description: 'Gate deleted'),
            new OAT\Response(response: 403, description: 'Missing gate.delete permission'),
            new OAT\Response(response: 404, description: 'Gate not found'),
            new OAT\Response(
                response: 422,
                description: 'Gate has recorded check-ins and cannot be deleted',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'deletion_prevented'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function destroy(Request $request, Gate $gate): JsonResponse|Response
    {
        abort_unless((bool) $request->user()?->can('gate.delete'), Response::HTTP_FORBIDDEN);

        if (CheckIn::where('gate_id', $gate->id)->exists()) {
            return response()->json([
                'code' => 'deletion_prevented',
                'message' => 'Cannot delete a gate that has recorded check-ins.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $gate->delete();

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'gate',
            'event' => 'deleted',
            'description' => 'Gate deleted',
            'causer_type' => $request->user()->getMorphClass(),
            'causer_id' => $request->user()->id,
            'subject_type' => $gate->getMorphClass(),
            'subject_id' => $gate->id,
            'properties' => ['gate_ulid' => $gate->ulid],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function resolveUlidReferences(array $validated): array
    {
        if (array_key_exists('event_session_ulid', $validated)) {
            $validated['event_session_id'] = $validated['event_session_ulid'] !== null
                ? EventSession::where('ulid', $validated['event_session_ulid'])->value('id')
                : null;
            unset($validated['event_session_ulid']);
        }

        if (array_key_exists('allowed_ticket_type_ulids', $validated)) {
            $validated['allowed_ticket_type_ids'] = $validated['allowed_ticket_type_ulids'] !== null
                ? TicketType::whereIn('ulid', $validated['allowed_ticket_type_ulids'])->pluck('id')->all()
                : null;
            unset($validated['allowed_ticket_type_ulids']);
        }

        return $validated;
    }
}
