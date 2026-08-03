<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\CheckIn\Models\EventSession;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerGateAssignment;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Api\Scanner\DeviceEnrolmentController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignVolunteerGateRequest;
use App\Http\Requests\Admin\RevokeVolunteerAccessRequest;
use App\Http\Requests\Admin\StoreVolunteerRequest;
use App\Http\Requests\Admin\UpdateVolunteerRequest;
use App\Http\Resources\VolunteerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

/**
 * Volunteer CRUD, gate assignment, and device enrolment. Device enrolment
 * itself works in two steps: an Event Manager mints a short-lived,
 * single-use token here, and the volunteer's phone exchanges that token
 * for a bound Sanctum token at {@see DeviceEnrolmentController::enrol()}.
 */
#[OAT\Tag(name: 'Volunteers')]
class VolunteerController extends Controller
{
    private const int ENROLMENT_TOKEN_TTL_MINUTES = 15;

    #[OAT\Get(
        path: '/admin/volunteers',
        summary: 'List volunteers',
        tags: ['Volunteers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'is_active', in: 'query', description: 'Filter by active status', schema: new OAT\Schema(type: 'boolean')),
            new OAT\Parameter(name: 'team', in: 'query', description: 'Filter by team', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'per_page', in: 'query', description: 'Results per page, capped at 100', schema: new OAT\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated list of volunteers'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing volunteer.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('volunteer.view_any'), Response::HTTP_FORBIDDEN);

        $query = VolunteerProfile::query()->with('user');

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('team')) {
            $query->where('team', (string) $request->input('team'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return VolunteerResource::collection($query->paginate($perPage));
    }

    #[OAT\Post(
        path: '/admin/volunteers',
        summary: 'Create a volunteer (a staff User plus a linked VolunteerProfile)',
        tags: ['Volunteers'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'name', type: 'string', required: ['name']),
                        new OAT\Property(property: 'email', type: 'string', format: 'email', required: ['email']),
                        new OAT\Property(property: 'phone', type: 'string', nullable: true),
                        new OAT\Property(property: 'password', type: 'string', required: ['password']),
                        new OAT\Property(property: 'volunteer_code', type: 'string', required: ['volunteer_code']),
                        new OAT\Property(property: 'team', type: 'string', nullable: true),
                        new OAT\Property(property: 'shift_starts_at', type: 'string', format: 'date-time', nullable: true),
                        new OAT\Property(property: 'shift_ends_at', type: 'string', format: 'date-time', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Volunteer created'),
            new OAT\Response(response: 403, description: 'Missing volunteer.create permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(StoreVolunteerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $volunteer = DB::transaction(function () use ($validated): VolunteerProfile {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => $validated['password'],
                'status' => 'active',
            ]);

            $user->assignRole('Volunteer');

            return VolunteerProfile::create([
                'user_id' => $user->id,
                'volunteer_code' => $validated['volunteer_code'],
                'team' => $validated['team'] ?? null,
                'shift_starts_at' => $validated['shift_starts_at'] ?? null,
                'shift_ends_at' => $validated['shift_ends_at'] ?? null,
                'is_active' => true,
            ]);
        });

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'volunteer',
            'event' => 'created',
            'description' => 'Volunteer created',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $volunteer->getMorphClass(),
            'subject_id' => $volunteer->id,
            'properties' => ['new' => $volunteer->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        $volunteer->load('user');

        return (new VolunteerResource($volunteer))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/volunteers/{volunteer}',
        summary: 'Get a single volunteer by ULID',
        tags: ['Volunteers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'volunteer', description: 'Volunteer profile ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Volunteer details'),
            new OAT\Response(response: 403, description: 'Missing volunteer.view_any permission'),
            new OAT\Response(response: 404, description: 'Volunteer not found'),
        ]
    )]
    public function show(Request $request, VolunteerProfile $volunteer): VolunteerResource
    {
        abort_unless((bool) $request->user()?->can('volunteer.view_any'), Response::HTTP_FORBIDDEN);

        $volunteer->load(['user', 'gateAssignments.gate', 'gateAssignments.eventSession']);

        return new VolunteerResource($volunteer);
    }

    #[OAT\Patch(
        path: '/admin/volunteers/{volunteer}',
        summary: 'Update a volunteer profile',
        tags: ['Volunteers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'volunteer', description: 'Volunteer profile ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'team', type: 'string', nullable: true),
                        new OAT\Property(property: 'shift_starts_at', type: 'string', format: 'date-time', nullable: true),
                        new OAT\Property(property: 'shift_ends_at', type: 'string', format: 'date-time', nullable: true),
                        new OAT\Property(property: 'is_active', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Volunteer updated'),
            new OAT\Response(response: 403, description: 'Missing volunteer.update permission'),
            new OAT\Response(response: 404, description: 'Volunteer not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(UpdateVolunteerRequest $request, VolunteerProfile $volunteer): VolunteerResource
    {
        $oldData = $volunteer->toArray();
        $validated = $request->validated();

        $volunteer->update($validated);

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'volunteer',
            'event' => 'updated',
            'description' => 'Volunteer updated',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $volunteer->getMorphClass(),
            'subject_id' => $volunteer->id,
            'properties' => ['old' => $oldData, 'new' => $volunteer->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new VolunteerResource($volunteer->refresh()->load('user'));
    }

    #[OAT\Post(
        path: '/admin/volunteers/{volunteer}/assign-gate',
        summary: 'Assign a volunteer to a gate',
        tags: ['Volunteers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'volunteer', description: 'Volunteer profile ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'gate_ulid', type: 'string', required: ['gate_ulid']),
                        new OAT\Property(property: 'event_session_ulid', type: 'string', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Gate assigned'),
            new OAT\Response(response: 403, description: 'Missing volunteer.assign_gate permission'),
            new OAT\Response(response: 404, description: 'Volunteer or gate not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function assignGate(AssignVolunteerGateRequest $request, VolunteerProfile $volunteer): JsonResponse
    {
        $validated = $request->validated();

        /** @var Gate $gate */
        $gate = Gate::where('ulid', $validated['gate_ulid'])->firstOrFail();

        $eventSessionId = null;
        if (! empty($validated['event_session_ulid'])) {
            $eventSessionId = EventSession::where('ulid', $validated['event_session_ulid'])->value('id');
        }

        $assignment = VolunteerGateAssignment::firstOrCreate([
            'volunteer_profile_id' => $volunteer->id,
            'gate_id' => $gate->id,
            'event_session_id' => $eventSessionId,
        ], [
            'assigned_by_user_id' => $request->user()?->id,
        ]);

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'volunteer',
            'event' => 'gate_assigned',
            'description' => 'Volunteer assigned to gate',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $volunteer->getMorphClass(),
            'subject_id' => $volunteer->id,
            'properties' => ['gate_ulid' => $gate->ulid, 'event_session_ulid' => $validated['event_session_ulid'] ?? null],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        $volunteer->load(['user', 'gateAssignments.gate', 'gateAssignments.eventSession']);

        return (new VolunteerResource($volunteer))
            ->response()
            ->setStatusCode($assignment->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[OAT\Post(
        path: '/admin/volunteers/{volunteer}/revoke-access',
        summary: 'Revoke a volunteer\'s access',
        description: 'Deactivates the volunteer profile. Does not revoke bound devices — use the devices revoke endpoint separately for those.',
        tags: ['Volunteers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'volunteer', description: 'Volunteer profile ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'reason', type: 'string', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Access revoked'),
            new OAT\Response(response: 403, description: 'Missing volunteer.revoke_access permission'),
            new OAT\Response(response: 404, description: 'Volunteer not found'),
        ]
    )]
    public function revokeAccess(RevokeVolunteerAccessRequest $request, VolunteerProfile $volunteer): VolunteerResource
    {
        $validated = $request->validated();

        $volunteer->forceFill([
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by_user_id' => $request->user()?->id,
        ])->save();

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'volunteer',
            'event' => 'access_revoked',
            'description' => 'Volunteer access revoked',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $volunteer->getMorphClass(),
            'subject_id' => $volunteer->id,
            'properties' => ['reason' => $validated['reason'] ?? null],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new VolunteerResource($volunteer->refresh()->load('user'));
    }

    #[OAT\Post(
        path: '/admin/volunteers/{volunteer}/enrolment-token',
        summary: 'Issue a single-use device enrolment token for a volunteer',
        description: 'Step one of device enrolment: an Event Manager mints a short-lived, '
            .'single-use token here. The volunteer\'s phone then exchanges that token for a '
            .'bound Sanctum scanner token at the scanner-guard enrolment endpoint '
            .'(DeviceEnrolmentController::enrol).',
        security: [['bearerAuth' => []]],
        tags: ['Volunteers'],
        parameters: [
            new OAT\Parameter(
                name: 'volunteer',
                description: 'Volunteer profile ULID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Enrolment token issued',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'enrolment_token',
                                type: 'string',
                                description: 'Single-use token, valid for 15 minutes'
                            ),
                            new OAT\Property(
                                property: 'expires_at',
                                type: 'string',
                                format: 'date-time'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing device.enrol permission'),
        ]
    )]
    public function issueEnrolmentToken(Request $request, VolunteerProfile $volunteer): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('device.enrol'), Response::HTTP_FORBIDDEN);

        $token = Str::random(40);

        Cache::put(
            "device-enrolment:{$token}",
            ['volunteer_profile_id' => $volunteer->id],
            now()->addMinutes(self::ENROLMENT_TOKEN_TTL_MINUTES)
        );

        return response()->json([
            'enrolment_token' => $token,
            'expires_at' => now()->addMinutes(self::ENROLMENT_TOKEN_TTL_MINUTES),
        ]);
    }
}
