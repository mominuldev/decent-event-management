<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Support\ListSort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Registrations')]
class RegistrationController extends Controller
{
    /**
     * Sortable columns, public field name => real column. Only columns of
     * `registrations` itself — attendee name and ticket type are shown in the
     * table but live behind a relation, and are marked unsortable in the SPA
     * rather than given a join. See ListSort for why the order is always total.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'registration_number' => 'registration_number',
        'status' => 'status',
        'total_paisa' => 'total_paisa',
        'created_at' => 'created_at',
    ];

    /** Newest first — this list had no ORDER BY at all before, so paging it was not even stable. */
    private const DEFAULT_SORT = 'created_at';

    #[OAT\Get(
        path: '/admin/registrations',
        summary: 'List registrations with search and filters',
        tags: ['Registrations'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'search',
                in: 'query',
                description: 'Search by registration number, attendee full name, or attendee mobile',
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\Parameter(
                name: 'status',
                in: 'query',
                description: 'Filter by registration status',
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\Parameter(
                name: 'ticket_type_id',
                in: 'query',
                description: 'Filter by ticket type id',
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\Parameter(
                name: 'date_from',
                in: 'query',
                description: 'Filter by created_at on or after this date',
                schema: new OAT\Schema(type: 'string', format: 'date')
            ),
            new OAT\Parameter(
                name: 'date_to',
                in: 'query',
                description: 'Filter by created_at on or before this date',
                schema: new OAT\Schema(type: 'string', format: 'date')
            ),
            new OAT\Parameter(
                name: 'sort',
                in: 'query',
                description: 'Column to order by. Unknown values fall back to the default.',
                schema: new OAT\Schema(type: 'string', default: 'created_at', enum: ['registration_number', 'status', 'total_paisa', 'created_at'])
            ),
            new OAT\Parameter(
                name: 'direction',
                in: 'query',
                description: 'Order direction. Defaults to newest first.',
                schema: new OAT\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc'])
            ),
            new OAT\Parameter(
                name: 'per_page',
                in: 'query',
                description: 'Results per page, capped at 100',
                schema: new OAT\Schema(type: 'integer', default: 15)
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Paginated list of registrations',
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
                                        new OAT\Property(property: 'registration_number', type: 'string'),
                                        new OAT\Property(property: 'status', type: 'string'),
                                        new OAT\Property(property: 'participation_type', type: 'string'),
                                        new OAT\Property(property: 'adults_count', type: 'integer'),
                                        new OAT\Property(property: 'children_count', type: 'integer'),
                                        new OAT\Property(property: 'subtotal_paisa', type: 'integer'),
                                        new OAT\Property(property: 'discount_paisa', type: 'integer'),
                                        new OAT\Property(property: 'total_paisa', type: 'integer'),
                                        new OAT\Property(property: 'currency', type: 'string'),
                                        new OAT\Property(property: 'discount_code', type: 'string', nullable: true),
                                        new OAT\Property(property: 'comments', type: 'string', nullable: true),
                                        new OAT\Property(property: 'special_notes', type: 'string', nullable: true),
                                        new OAT\Property(property: 'source', type: 'string'),
                                        new OAT\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'confirmed_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                        new OAT\Property(property: 'attendee', type: 'object'),
                                        new OAT\Property(property: 'ticket_type', type: 'object'),
                                        new OAT\Property(property: 'guests', type: 'array', items: new OAT\Items(type: 'object')),
                                        new OAT\Property(property: 'event_session', type: 'object'),
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
            new OAT\Response(response: 403, description: 'Missing registration.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('registration.view_any'), Response::HTTP_FORBIDDEN);

        $query = Registration::query()->with(['attendee', 'guests', 'ticketType', 'payments']);

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $q) use ($search): void {
                $q->where('registration_number', 'like', "%{$search}%")
                    ->orWhereHas('attendee', function (Builder $q2) use ($search): void {
                        $q2->where('full_name', 'like', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('ticket_type_id')) {
            $query->where('ticket_type_id', (string) $request->input('ticket_type_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->input('date_to'));
        }

        ListSort::apply($query, $request->all(), self::SORTABLE, self::DEFAULT_SORT);

        $perPage = min((int) $request->input('per_page', 15), 100);

        return RegistrationResource::collection($query->paginate($perPage));
    }

    #[OAT\Get(
        path: '/admin/registrations/{registration}',
        summary: 'Get a single registration',
        tags: ['Registrations'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'registration',
                in: 'path',
                required: true,
                description: 'Registration ULID',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Registration detail',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'registration_number', type: 'string'),
                                    new OAT\Property(property: 'status', type: 'string'),
                                    new OAT\Property(property: 'participation_type', type: 'string'),
                                    new OAT\Property(property: 'adults_count', type: 'integer'),
                                    new OAT\Property(property: 'children_count', type: 'integer'),
                                    new OAT\Property(property: 'subtotal_paisa', type: 'integer'),
                                    new OAT\Property(property: 'discount_paisa', type: 'integer'),
                                    new OAT\Property(property: 'total_paisa', type: 'integer'),
                                    new OAT\Property(property: 'currency', type: 'string'),
                                    new OAT\Property(property: 'discount_code', type: 'string', nullable: true),
                                    new OAT\Property(property: 'comments', type: 'string', nullable: true),
                                    new OAT\Property(property: 'special_notes', type: 'string', nullable: true),
                                    new OAT\Property(property: 'source', type: 'string'),
                                    new OAT\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'confirmed_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(property: 'attendee', type: 'object'),
                                    new OAT\Property(property: 'ticket_type', type: 'object'),
                                    new OAT\Property(property: 'guests', type: 'array', items: new OAT\Items(type: 'object')),
                                    new OAT\Property(property: 'event_session', type: 'object'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing registration.view permission'),
            new OAT\Response(response: 404, description: 'Registration not found'),
        ]
    )]
    public function show(Request $request, Registration $registration): RegistrationResource
    {
        abort_unless((bool) $request->user()?->can('registration.view'), Response::HTTP_FORBIDDEN);

        $registration->load([
            'attendee',
            'guests',
            'ticketType',
            'payments',
            'tickets',
        ]);

        return new RegistrationResource($registration);
    }

    #[OAT\Patch(
        path: '/admin/registrations/{registration}',
        summary: 'Update a registration (status, comments, notes)',
        tags: ['Registrations'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'registration',
                in: 'path',
                required: true,
                description: 'Registration ULID',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'status',
                            type: 'string',
                            enum: ['draft', 'pending_payment', 'pending_approval', 'approved', 'rejected', 'cancelled'],
                            description: 'New registration status, applied via the state machine'
                        ),
                        new OAT\Property(property: 'comments', type: 'string', nullable: true, maxLength: 1000),
                        new OAT\Property(property: 'special_notes', type: 'string', nullable: true, maxLength: 1000),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Registration updated',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'registration_number', type: 'string'),
                                    new OAT\Property(property: 'status', type: 'string'),
                                    new OAT\Property(property: 'comments', type: 'string', nullable: true),
                                    new OAT\Property(property: 'special_notes', type: 'string', nullable: true),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing registration.update permission'),
            new OAT\Response(response: 404, description: 'Registration not found'),
            new OAT\Response(response: 422, description: 'Validation error, or the requested status transition is not permitted'),
        ]
    )]
    public function update(UpdateRegistrationRequest $request, Registration $registration): RegistrationResource
    {
        $oldData = $registration->toArray();
        $validated = $request->validated();

        if (isset($validated['status']) && $validated['status'] !== $registration->status) {
            $registration->transitionTo((string) $validated['status']);
        }

        unset($validated['status']);

        if (! empty($validated)) {
            $registration->update($validated);
        }

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'registration',
            'event' => 'updated',
            'description' => 'Registration updated',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $registration->getMorphClass(),
            'subject_id' => $registration->id,
            'properties' => ['old' => $oldData, 'new' => $registration->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new RegistrationResource($registration->refresh());
    }

    #[OAT\Delete(
        path: '/admin/registrations/{registration}',
        summary: 'Delete a registration (only when not paid/confirmed)',
        tags: ['Registrations'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'registration',
                in: 'path',
                required: true,
                description: 'Registration ULID',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(response: 204, description: 'Registration deleted'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing registration.delete permission'),
            new OAT\Response(response: 404, description: 'Registration not found'),
            new OAT\Response(
                response: 422,
                description: 'Registration is paid or confirmed and cannot be deleted',
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
    public function destroy(Request $request, Registration $registration): JsonResponse|Response
    {
        abort_unless((bool) $request->user()?->can('registration.delete'), Response::HTTP_FORBIDDEN);

        if (in_array($registration->status, ['paid', 'confirmed'], true)) {
            return response()->json([
                'code' => 'deletion_prevented',
                'message' => 'Cannot delete paid or confirmed registrations.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $registration->delete();

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'registration',
            'event' => 'deleted',
            'description' => 'Registration soft deleted',
            'causer_type' => $request->user()->getMorphClass(),
            'causer_id' => $request->user()->id,
            'subject_type' => $registration->getMorphClass(),
            'subject_id' => $registration->id,
            'properties' => ['registration_ulid' => $registration->ulid],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return response()->noContent();
    }
}
