<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\CheckIn\Actions\ProcessCheckIn;
use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerGateAssignment;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManualOverrideCheckInRequest;
use App\Http\Requests\Admin\ResolveCheckInConflictRequest;
use App\Http\Resources\AdminCheckInResource;
use App\Http\Resources\GateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Check-ins')]
class CheckInController extends Controller
{
    public function __construct(
        private readonly ProcessCheckIn $processCheckIn
    ) {}

    #[OAT\Get(
        path: '/admin/check-ins',
        summary: 'List check-ins (the gate scan/dispute log) with filters',
        tags: ['Check-ins'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'gate_ulid', in: 'query', description: 'Filter by gate ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'event_session_ulid', in: 'query', description: 'Filter by event session ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'ticket_ulid', in: 'query', description: 'Filter by ticket ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'result', in: 'query', description: 'Filter by scan result', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'date_from', in: 'query', description: 'Filter by scanned_at on or after this date', schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\Parameter(name: 'date_to', in: 'query', description: 'Filter by scanned_at on or before this date', schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\Parameter(name: 'per_page', in: 'query', description: 'Results per page, capped at 100', schema: new OAT\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated list of check-ins'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing checkin.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('checkin.view_any'), Response::HTTP_FORBIDDEN);

        $query = CheckIn::query()->with(['gate', 'ticket'])->orderByDesc('scanned_at');

        if ($request->filled('gate_ulid')) {
            $gateUlid = (string) $request->input('gate_ulid');
            $query->whereHas('gate', function ($q) use ($gateUlid): void {
                $q->where('ulid', $gateUlid);
            });
        }

        if ($request->filled('event_session_ulid')) {
            $eventSessionUlid = (string) $request->input('event_session_ulid');
            $query->whereHas('eventSession', function ($q) use ($eventSessionUlid): void {
                $q->where('ulid', $eventSessionUlid);
            });
        }

        if ($request->filled('ticket_ulid')) {
            $ticketUlid = (string) $request->input('ticket_ulid');
            $query->whereHas('ticket', function ($q) use ($ticketUlid): void {
                $q->where('ulid', $ticketUlid);
            });
        }

        if ($request->filled('result')) {
            $query->where('result', (string) $request->input('result'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('scanned_at', '>=', (string) $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('scanned_at', '<=', (string) $request->input('date_to'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return AdminCheckInResource::collection($query->paginate($perPage));
    }

    #[OAT\Get(
        path: '/admin/check-ins/{check_in}',
        summary: 'Get a single check-in, including override and conflict-resolution detail',
        tags: ['Check-ins'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'check_in', description: 'Check-in ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Check-in detail'),
            new OAT\Response(response: 403, description: 'Missing checkin.view permission'),
            new OAT\Response(response: 404, description: 'Check-in not found'),
        ]
    )]
    public function show(Request $request, CheckIn $checkIn): AdminCheckInResource
    {
        abort_unless((bool) $request->user()?->can('checkin.view'), Response::HTTP_FORBIDDEN);

        $checkIn->load(['gate', 'ticket', 'registration', 'attendee', 'device', 'scannedBy', 'overrideBy', 'conflictResolvedBy']);

        return new AdminCheckInResource($checkIn);
    }

    #[OAT\Post(
        path: '/admin/check-ins/manual-override',
        summary: 'Manually admit a ticket whose QR will not scan',
        description: 'The single highest-abuse-risk action at the gate (docs/02 §2.4) — deliberately excludes Volunteers, always requires a reason, and is always logged to activity_logs.',
        tags: ['Check-ins'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'ticket_ulid', type: 'string', required: ['ticket_ulid']),
                        new OAT\Property(property: 'gate_ulid', type: 'string', required: ['gate_ulid']),
                        new OAT\Property(property: 'party_size', type: 'integer', minimum: 1, maximum: 20, required: ['party_size']),
                        new OAT\Property(property: 'reason', type: 'string', maxLength: 255, required: ['reason']),
                        new OAT\Property(property: 'client_scan_uuid', type: 'string', format: 'uuid', nullable: true, description: 'Generated server-side if omitted'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Check-in recorded'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing checkin.manual_override permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function manualOverride(ManualOverrideCheckInRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var Ticket $ticket */
        $ticket = Ticket::where('ulid', $validated['ticket_ulid'])->firstOrFail();

        /** @var Gate $gate */
        $gate = Gate::where('ulid', $validated['gate_ulid'])->firstOrFail();

        /** @var User $user */
        $user = $request->user();

        $checkIn = $this->processCheckIn->execute(
            clientScanUuid: $validated['client_scan_uuid'] ?? (string) Str::uuid(),
            rawPayload: 'DTM1.'.$ticket->ulid.'.admin-override.0.0.0',
            partySize: (int) $validated['party_size'],
            gate: $gate,
            scannedBy: $user,
            isManualOverride: true
        );

        $checkIn->update(['override_reason' => $validated['reason']]);

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'checkin',
            'event' => 'manual_override',
            'description' => 'Manual check-in override',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $checkIn->getMorphClass(),
            'subject_id' => $checkIn->id,
            'properties' => [
                'ticket_ulid' => $ticket->ulid,
                'gate_ulid' => $gate->ulid,
                'reason' => $validated['reason'],
                'result' => $checkIn->result,
            ],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        $checkIn->load(['gate', 'ticket']);

        return (new AdminCheckInResource($checkIn))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Post(
        path: '/admin/check-ins/{check_in}/resolve-conflict',
        summary: 'Mark a flagged check-in conflict as resolved',
        description: 'Nothing in this codebase sets conflict_flag=true yet (offline-sync conflict detection is unbuilt), so this will 422 against every real check-in today. Shipped now so the SPA surface and permission are ready once conflict detection lands.',
        tags: ['Check-ins'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'check_in', description: 'Check-in ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Conflict marked resolved'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing checkin.resolve_conflict permission'),
            new OAT\Response(response: 404, description: 'Check-in not found'),
            new OAT\Response(
                response: 422,
                description: 'Check-in has no unresolved conflict',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'no_conflict_to_resolve'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function resolveConflict(ResolveCheckInConflictRequest $request, CheckIn $checkIn): AdminCheckInResource|JsonResponse
    {
        if (! $checkIn->conflict_flag || $checkIn->conflict_resolved_at !== null) {
            return response()->json([
                'code' => 'no_conflict_to_resolve',
                'message' => 'This check-in has no unresolved conflict.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validated();

        $checkIn->update([
            'conflict_resolved_at' => now(),
            'conflict_resolved_by_user_id' => $request->user()?->id,
        ]);

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'checkin',
            'event' => 'conflict_resolved',
            'description' => 'Check-in conflict resolved',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $checkIn->getMorphClass(),
            'subject_id' => $checkIn->id,
            'properties' => ['note' => $validated['note'] ?? null],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        $checkIn->load(['gate', 'ticket', 'conflictResolvedBy']);

        return new AdminCheckInResource($checkIn->refresh());
    }

    #[OAT\Get(
        path: '/admin/check-ins/live-dashboard',
        summary: 'Live per-gate admission counts and recent scans',
        description: 'Super Admin and Event Manager see every gate; a Volunteer sees only gates assigned to them via volunteer_gate_assignments (docs/02 §2.4).',
        tags: ['Check-ins'],
        security: [['bearerAuth' => []]],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Per-gate live counts plus the 10 most recent check-ins',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'gates', type: 'array', items: new OAT\Items(type: 'object')),
                            new OAT\Property(property: 'recent_check_ins', type: 'array', items: new OAT\Items(type: 'object')),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing checkin.view_live_dashboard permission'),
        ]
    )]
    public function liveDashboard(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('checkin.view_live_dashboard'), Response::HTTP_FORBIDDEN);

        /** @var User $user */
        $user = $request->user();

        $gateQuery = Gate::query()->with('eventSession')->where('is_active', true);

        $volunteerProfile = $user->volunteerProfile;

        if ($volunteerProfile !== null) {
            $assignedGateIds = VolunteerGateAssignment::where('volunteer_profile_id', $volunteerProfile->id)->pluck('gate_id');
            $gateQuery->whereIn('id', $assignedGateIds);
        }

        $gates = $gateQuery->get();

        $recentCheckInsQuery = CheckIn::query()->with(['gate', 'ticket'])->orderByDesc('scanned_at')->limit(10);

        if ($volunteerProfile !== null) {
            $recentCheckInsQuery->whereIn('gate_id', $gates->pluck('id'));
        }

        return response()->json([
            'gates' => GateResource::collection($gates),
            'recent_check_ins' => AdminCheckInResource::collection($recentCheckInsQuery->get()),
        ]);
    }
}
