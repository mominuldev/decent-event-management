<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Ticketing\Models\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTicketTypeRequest;
use App\Http\Requests\Admin\UpdateTicketTypeRequest;
use App\Http\Resources\TicketTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Ticket Types')]
class TicketTypeController extends Controller
{
    #[OAT\Get(
        path: '/admin/ticket-types',
        summary: 'List all ticket types, ordered by sort_order',
        tags: ['Ticket Types'],
        security: [['bearerAuth' => []]],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'List of ticket types',
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
                                        new OAT\Property(property: 'name_bn', type: 'string', nullable: true),
                                        new OAT\Property(property: 'description', type: 'string', nullable: true),
                                        new OAT\Property(property: 'base_price_paisa', type: 'integer', description: 'Amount in paisa (1 BDT = 100 paisa)'),
                                        new OAT\Property(property: 'additional_adult_price_paisa', type: 'integer', description: 'Amount in paisa'),
                                        new OAT\Property(property: 'additional_child_price_paisa', type: 'integer', description: 'Amount in paisa'),
                                        new OAT\Property(property: 'current_student_price_paisa', type: 'integer', nullable: true, description: 'Amount in paisa, or null when this type has no student rate.'),
                                        new OAT\Property(property: 'currency', type: 'string'),
                                        new OAT\Property(property: 'base_admits', type: 'integer'),
                                        new OAT\Property(property: 'max_admits', type: 'integer'),
                                        new OAT\Property(property: 'allowed_participant_types', type: 'array', items: new OAT\Items(type: 'string'), nullable: true),
                                        new OAT\Property(property: 'quantity_total', type: 'integer', nullable: true),
                                        new OAT\Property(property: 'quantity_sold', type: 'integer'),
                                        new OAT\Property(property: 'quantity_reserved', type: 'integer'),
                                        new OAT\Property(property: 'quantity_available', type: 'integer'),
                                        new OAT\Property(property: 'requires_approval', type: 'boolean'),
                                        new OAT\Property(property: 'includes_tshirt', type: 'boolean'),
                                        new OAT\Property(property: 'includes_meal', type: 'boolean'),
                                        new OAT\Property(property: 'sale_starts_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'sale_ends_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'is_active', type: 'boolean'),
                                        new OAT\Property(property: 'is_public', type: 'boolean'),
                                        new OAT\Property(property: 'badge_color', type: 'string', nullable: true),
                                        new OAT\Property(property: 'sort_order', type: 'integer'),
                                    ],
                                    type: 'object'
                                )
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing ticket_type.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('ticket_type.view_any'), Response::HTTP_FORBIDDEN);

        $ticketTypes = TicketType::query()->orderBy('sort_order')->get();

        return TicketTypeResource::collection($ticketTypes);
    }

    #[OAT\Post(
        path: '/admin/ticket-types',
        summary: 'Create a new ticket type',
        tags: ['Ticket Types'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'code', type: 'string', description: 'Unique short code, max 16 chars', required: ['code']),
                        new OAT\Property(property: 'name', type: 'string', required: ['name']),
                        new OAT\Property(property: 'name_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'description', type: 'string', nullable: true),
                        new OAT\Property(property: 'base_price_paisa', type: 'integer', description: 'Amount in paisa (1 BDT = 100 paisa)', required: ['base_price_paisa']),
                        new OAT\Property(property: 'additional_adult_price_paisa', type: 'integer', description: 'Amount in paisa', required: ['additional_adult_price_paisa']),
                        new OAT\Property(property: 'additional_child_price_paisa', type: 'integer', description: 'Amount in paisa', required: ['additional_child_price_paisa']),
                        new OAT\Property(property: 'current_student_price_paisa', type: 'integer', nullable: true, description: 'Amount in paisa. What a current student pays for their own seat; null means they pay base_price_paisa.'),
                        new OAT\Property(property: 'currency', type: 'string', nullable: true, description: 'ISO 4217 currency code, e.g. BDT'),
                        new OAT\Property(property: 'base_admits', type: 'integer', required: ['base_admits']),
                        new OAT\Property(property: 'max_admits', type: 'integer', description: 'Must be >= base_admits', required: ['max_admits']),
                        new OAT\Property(
                            property: 'allowed_participant_types',
                            type: 'array',
                            items: new OAT\Items(type: 'string', enum: ['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'other']),
                            nullable: true
                        ),
                        new OAT\Property(property: 'quantity_total', type: 'integer', nullable: true),
                        new OAT\Property(property: 'requires_approval', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'includes_tshirt', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'includes_meal', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'sale_starts_at', type: 'string', format: 'date-time', nullable: true),
                        new OAT\Property(property: 'sale_ends_at', type: 'string', format: 'date-time', nullable: true, description: 'Must be after sale_starts_at'),
                        new OAT\Property(property: 'is_active', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'is_public', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'badge_color', type: 'string', nullable: true, description: 'Hex color, max 7 chars'),
                        new OAT\Property(property: 'sort_order', type: 'integer', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 201,
                description: 'Ticket type created',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'data', type: 'object', description: 'The created ticket type (see GET /admin/ticket-types/{ticket_type} for shape)'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing ticket_type.manage permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(StoreTicketTypeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ticketType = TicketType::create($validated);

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'ticket_type',
            'event' => 'created',
            'description' => 'Ticket type created',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $ticketType->getMorphClass(),
            'subject_id' => $ticketType->id,
            'properties' => ['new' => $ticketType->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return (new TicketTypeResource($ticketType))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/ticket-types/{ticket_type}',
        summary: 'Get a single ticket type by ULID',
        tags: ['Ticket Types'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'ticket_type', description: 'Ticket type ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Ticket type details',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'ulid', type: 'string'),
                            new OAT\Property(property: 'code', type: 'string'),
                            new OAT\Property(property: 'name', type: 'string'),
                            new OAT\Property(property: 'name_bn', type: 'string', nullable: true),
                            new OAT\Property(property: 'description', type: 'string', nullable: true),
                            new OAT\Property(property: 'base_price_paisa', type: 'integer', description: 'Amount in paisa (1 BDT = 100 paisa)'),
                            new OAT\Property(property: 'additional_adult_price_paisa', type: 'integer', description: 'Amount in paisa'),
                            new OAT\Property(property: 'additional_child_price_paisa', type: 'integer', description: 'Amount in paisa'),
                            new OAT\Property(property: 'current_student_price_paisa', type: 'integer', nullable: true, description: 'Amount in paisa, or null when this type has no student rate.'),
                            new OAT\Property(property: 'currency', type: 'string'),
                            new OAT\Property(property: 'base_admits', type: 'integer'),
                            new OAT\Property(property: 'max_admits', type: 'integer'),
                            new OAT\Property(property: 'allowed_participant_types', type: 'array', items: new OAT\Items(type: 'string'), nullable: true),
                            new OAT\Property(property: 'quantity_total', type: 'integer', nullable: true),
                            new OAT\Property(property: 'quantity_sold', type: 'integer'),
                            new OAT\Property(property: 'quantity_reserved', type: 'integer'),
                            new OAT\Property(property: 'quantity_available', type: 'integer'),
                            new OAT\Property(property: 'requires_approval', type: 'boolean'),
                            new OAT\Property(property: 'includes_tshirt', type: 'boolean'),
                            new OAT\Property(property: 'includes_meal', type: 'boolean'),
                            new OAT\Property(property: 'sale_starts_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'sale_ends_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'is_active', type: 'boolean'),
                            new OAT\Property(property: 'is_public', type: 'boolean'),
                            new OAT\Property(property: 'badge_color', type: 'string', nullable: true),
                            new OAT\Property(property: 'sort_order', type: 'integer'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing ticket_type.view_any permission'),
            new OAT\Response(response: 404, description: 'Ticket type not found'),
        ]
    )]
    public function show(Request $request, TicketType $ticketType): TicketTypeResource
    {
        abort_unless((bool) $request->user()?->can('ticket_type.view_any'), Response::HTTP_FORBIDDEN);

        return new TicketTypeResource($ticketType);
    }

    #[OAT\Patch(
        path: '/admin/ticket-types/{ticket_type}',
        summary: 'Update a ticket type',
        description: 'Price and code fields cannot be changed once quantity_sold > 0.',
        tags: ['Ticket Types'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'ticket_type', description: 'Ticket type ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'code', type: 'string', description: 'Unique short code, max 16 chars. Locked after sales start.'),
                        new OAT\Property(property: 'name', type: 'string'),
                        new OAT\Property(property: 'name_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'description', type: 'string', nullable: true),
                        new OAT\Property(property: 'base_price_paisa', type: 'integer', description: 'Amount in paisa. Locked after sales start.'),
                        new OAT\Property(property: 'additional_adult_price_paisa', type: 'integer', description: 'Amount in paisa. Locked after sales start.'),
                        new OAT\Property(property: 'additional_child_price_paisa', type: 'integer', description: 'Amount in paisa. Locked after sales start.'),
                        new OAT\Property(property: 'current_student_price_paisa', type: 'integer', nullable: true, description: 'Amount in paisa. Locked after sales start.'),
                        new OAT\Property(property: 'currency', type: 'string', nullable: true),
                        new OAT\Property(property: 'base_admits', type: 'integer'),
                        new OAT\Property(property: 'max_admits', type: 'integer', description: 'Must be >= base_admits'),
                        new OAT\Property(
                            property: 'allowed_participant_types',
                            type: 'array',
                            items: new OAT\Items(type: 'string', enum: ['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'other']),
                            nullable: true
                        ),
                        new OAT\Property(property: 'quantity_total', type: 'integer', nullable: true),
                        new OAT\Property(property: 'requires_approval', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'includes_tshirt', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'includes_meal', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'sale_starts_at', type: 'string', format: 'date-time', nullable: true),
                        new OAT\Property(property: 'sale_ends_at', type: 'string', format: 'date-time', nullable: true, description: 'Must be after sale_starts_at'),
                        new OAT\Property(property: 'is_active', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'is_public', type: 'boolean', nullable: true),
                        new OAT\Property(property: 'badge_color', type: 'string', nullable: true),
                        new OAT\Property(property: 'sort_order', type: 'integer', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Ticket type updated',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'data', type: 'object', description: 'The updated ticket type (see GET /admin/ticket-types/{ticket_type} for shape)'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing ticket_type.manage permission'),
            new OAT\Response(
                response: 422,
                description: 'Attempted to change price or code after sales have started, or validation failed',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'update_prevented'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function update(UpdateTicketTypeRequest $request, TicketType $ticketType): TicketTypeResource|JsonResponse
    {
        $validated = $request->validated();

        if ($ticketType->quantity_sold > 0) {
            $restrictedKeys = ['code', 'base_price_paisa', 'additional_adult_price_paisa', 'additional_child_price_paisa', 'current_student_price_paisa'];
            foreach ($restrictedKeys as $key) {
                if (array_key_exists($key, $validated) && $validated[$key] !== $ticketType->{$key}) {
                    return response()->json([
                        'code' => 'update_prevented',
                        'message' => 'Cannot change price or code after sales have started.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }
        }

        $oldData = $ticketType->toArray();
        $ticketType->update($validated);

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'ticket_type',
            'event' => 'updated',
            'description' => 'Ticket type updated',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $ticketType->getMorphClass(),
            'subject_id' => $ticketType->id,
            'properties' => ['old' => $oldData, 'new' => $ticketType->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new TicketTypeResource($ticketType->refresh());
    }

    #[OAT\Delete(
        path: '/admin/ticket-types/{ticket_type}',
        summary: 'Delete a ticket type',
        description: 'Super-Admin-only. Cannot delete a ticket type that has any sold or reserved quantity.',
        tags: ['Ticket Types'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'ticket_type', description: 'Ticket type ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 204, description: 'Ticket type deleted'),
            new OAT\Response(response: 403, description: 'Missing ticket_type.delete permission'),
            new OAT\Response(
                response: 422,
                description: 'Ticket type has sold or reserved quantities and cannot be deleted',
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
    public function destroy(Request $request, TicketType $ticketType): JsonResponse|Response
    {
        abort_unless((bool) $request->user()?->can('ticket_type.delete'), Response::HTTP_FORBIDDEN);

        if ($ticketType->quantity_sold > 0 || $ticketType->quantity_reserved > 0) {
            return response()->json([
                'code' => 'deletion_prevented',
                'message' => 'Cannot delete ticket type that has sold or reserved quantities.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticketType->delete();

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'ticket_type',
            'event' => 'deleted',
            'description' => 'Ticket type soft deleted',
            'causer_type' => $request->user()->getMorphClass(),
            'causer_id' => $request->user()->id,
            'subject_type' => $ticketType->getMorphClass(),
            'subject_id' => $ticketType->id,
            'properties' => ['ticket_type_ulid' => $ticketType->ulid],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return response()->noContent();
    }
}
