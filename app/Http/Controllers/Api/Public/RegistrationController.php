<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Registration\Actions\CreateRegistration;
use App\Domain\Registration\Models\Registration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Public')]
class RegistrationController extends Controller
{
    #[OAT\Post(
        path: '/public/registrations',
        summary: 'Submit a new registration (attendee details, ticket selection, guests)',
        tags: ['Public'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'full_name', type: 'string', maxLength: 200, required: ['full_name']),
                        new OAT\Property(property: 'full_name_bn', type: 'string', maxLength: 200, description: 'Bengali name'),
                        new OAT\Property(property: 'mobile', type: 'string', maxLength: 20, required: ['mobile']),
                        new OAT\Property(property: 'email', type: 'string', format: 'email', maxLength: 254),
                        new OAT\Property(property: 'gender', type: 'string', enum: ['male', 'female'], required: ['gender']),
                        new OAT\Property(property: 'date_of_birth', type: 'string', format: 'date'),
                        new OAT\Property(property: 'occupation', type: 'string', maxLength: 100),
                        new OAT\Property(property: 'designation', type: 'string', maxLength: 100),
                        new OAT\Property(property: 'organization', type: 'string', maxLength: 200),
                        new OAT\Property(
                            property: 'participant_type',
                            type: 'string',
                            enum: ['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'other'],
                            required: ['participant_type']
                        ),
                        new OAT\Property(
                            property: 'ssc_batch_year',
                            type: 'integer',
                            description: 'Required when participant_type is current_student or former_student',
                            minimum: 1971
                        ),
                        new OAT\Property(property: 'current_class', type: 'string', maxLength: 50),
                        new OAT\Property(property: 'ticket_type_ulid', type: 'string', description: 'ULID of an active, public TicketType', required: ['ticket_type_ulid']),
                        new OAT\Property(property: 'event_session_ulid', type: 'string', description: 'ULID of an EventSession'),
                        new OAT\Property(
                            property: 'participation_type',
                            type: 'string',
                            enum: ['single', 'couple', 'family'],
                            required: ['participation_type']
                        ),
                        new OAT\Property(property: 'adults_count', type: 'integer', minimum: 1, maximum: 10, required: ['adults_count']),
                        new OAT\Property(property: 'children_count', type: 'integer', minimum: 0, maximum: 10, required: ['children_count']),
                        new OAT\Property(
                            property: 'guests',
                            type: 'array',
                            description: 'Accompanying guests (spouse, children, etc.)',
                            items: new OAT\Items(
                                properties: [
                                    new OAT\Property(property: 'full_name', type: 'string', maxLength: 200, required: ['full_name']),
                                    new OAT\Property(
                                        property: 'relation',
                                        type: 'string',
                                        enum: ['spouse', 'child', 'parent', 'sibling', 'other'],
                                        required: ['relation']
                                    ),
                                    new OAT\Property(property: 'age_group', type: 'string', enum: ['adult', 'child'], required: ['age_group']),
                                    new OAT\Property(property: 'age', type: 'integer', minimum: 0, maximum: 120),
                                    new OAT\Property(property: 'gender', type: 'string', enum: ['male', 'female']),
                                    new OAT\Property(property: 'tshirt_required', type: 'boolean'),
                                    new OAT\Property(
                                        property: 'tshirt_size',
                                        type: 'string',
                                        enum: ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
                                        description: 'Required when this guest\'s tshirt_required is true'
                                    ),
                                ],
                                type: 'object'
                            )
                        ),
                        new OAT\Property(property: 'tshirt_required', type: 'boolean'),
                        new OAT\Property(
                            property: 'tshirt_size',
                            type: 'string',
                            enum: ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
                            description: 'Required when tshirt_required is true'
                        ),
                        new OAT\Property(property: 'comments', type: 'string', maxLength: 1000),
                        new OAT\Property(property: 'special_notes', type: 'string', maxLength: 1000),
                        new OAT\Property(
                            property: 'idempotency_key',
                            type: 'string',
                            maxLength: 64,
                            description: 'Client-generated key; a retried submission with the same key produces one registration',
                            required: ['idempotency_key']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 201,
                description: 'Registration created',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'object',
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
                                    new OAT\Property(property: 'discount_code', type: 'string'),
                                    new OAT\Property(property: 'comments', type: 'string'),
                                    new OAT\Property(property: 'special_notes', type: 'string'),
                                    new OAT\Property(property: 'source', type: 'string'),
                                    new OAT\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(property: 'confirmed_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(property: 'cancelled_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ]
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreRegistrationRequest $request, CreateRegistration $action): JsonResponse
    {
        $registration = $action($request->validated());

        return (new RegistrationResource($registration))
            ->response()
            ->setStatusCode(201);
    }

    #[OAT\Get(
        path: '/public/registrations/{registration}',
        summary: 'Look up a registration by ULID (status check page)',
        tags: ['Public'],
        parameters: [
            new OAT\Parameter(
                name: 'registration',
                description: 'Registration ULID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Registration details with attendee, ticket type, and guests',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'object',
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
                                    new OAT\Property(property: 'discount_code', type: 'string'),
                                    new OAT\Property(property: 'comments', type: 'string'),
                                    new OAT\Property(property: 'special_notes', type: 'string'),
                                    new OAT\Property(property: 'source', type: 'string'),
                                    new OAT\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(property: 'confirmed_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(property: 'cancelled_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(
                                        property: 'attendee',
                                        type: 'object',
                                        properties: [
                                            new OAT\Property(property: 'ulid', type: 'string'),
                                            new OAT\Property(property: 'full_name', type: 'string'),
                                            new OAT\Property(property: 'full_name_bn', type: 'string'),
                                            new OAT\Property(property: 'mobile', type: 'string'),
                                            new OAT\Property(property: 'email', type: 'string', format: 'email'),
                                            new OAT\Property(property: 'gender', type: 'string'),
                                            new OAT\Property(property: 'participant_type', type: 'string'),
                                            new OAT\Property(property: 'ssc_batch_year', type: 'integer'),
                                        ]
                                    ),
                                    new OAT\Property(
                                        property: 'ticket_type',
                                        type: 'object',
                                        properties: [
                                            new OAT\Property(property: 'ulid', type: 'string'),
                                            new OAT\Property(property: 'code', type: 'string'),
                                            new OAT\Property(property: 'name', type: 'string'),
                                        ]
                                    ),
                                    new OAT\Property(
                                        property: 'guests',
                                        type: 'array',
                                        items: new OAT\Items(
                                            properties: [
                                                new OAT\Property(property: 'ulid', type: 'string'),
                                                new OAT\Property(property: 'full_name', type: 'string'),
                                                new OAT\Property(property: 'relation', type: 'string'),
                                                new OAT\Property(property: 'age_group', type: 'string'),
                                            ],
                                            type: 'object'
                                        )
                                    ),
                                ]
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 404, description: 'Registration not found'),
        ]
    )]
    public function show(Registration $registration): RegistrationResource
    {
        $registration->load(['attendee', 'guests', 'ticketType', 'payments']);

        return new RegistrationResource($registration);
    }
}
