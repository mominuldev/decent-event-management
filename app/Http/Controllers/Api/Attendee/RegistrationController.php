<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\UpdateRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Attendee Self-Service')]
class RegistrationController extends Controller
{
    #[OAT\Get(
        path: '/attendee/registrations',
        summary: 'List the authenticated attendee\'s own registrations',
        description: 'Query-scoped to `attendee_id = auth()->id()` at the builder level, so an attendee can never see another attendee\'s registrations.',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'List of registrations belonging to the authenticated attendee',
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
                                        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(
                                            property: 'tickets',
                                            type: 'array',
                                            items: new OAT\Items(
                                                properties: [
                                                    new OAT\Property(property: 'ulid', type: 'string'),
                                                    new OAT\Property(property: 'ticket_number', type: 'string'),
                                                    new OAT\Property(property: 'status', type: 'string'),
                                                ],
                                                type: 'object'
                                            )
                                        ),
                                    ],
                                    type: 'object'
                                )
                            ),
                        ]
                    )
                )
            ),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        // `attendee` included because RegistrationResource exposes it through
        // `whenLoaded()`: without it the key is omitted entirely, and the
        // dashboard's "primary attendee" panel renders a label, an icon and
        // two blank lines. It is the caller's own record, so this loads
        // nothing they are not already entitled to see.
        $registrations = Registration::with(['attendee', 'guests', 'ticketType', 'eventSession', 'tickets'])
            ->where('attendee_id', $attendee->id)
            ->get();

        return RegistrationResource::collection($registrations);
    }

    #[OAT\Patch(
        path: '/attendee/registrations/{registration}',
        summary: 'Update special notes and the guest list on an owned registration',
        description: 'Only allowed while the registration is still `draft` or `pending_payment` and belongs to the authenticated attendee — enforced by UpdateRegistrationRequest::authorize().',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        parameters: [
            new OAT\Parameter(
                name: 'registration',
                description: 'Registration ULID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'special_notes', type: 'string', nullable: true),
                        new OAT\Property(
                            property: 'guests',
                            type: 'array',
                            items: new OAT\Items(
                                properties: [
                                    new OAT\Property(property: 'full_name', type: 'string', required: ['full_name']),
                                    new OAT\Property(property: 'relation', type: 'string', required: ['relation']),
                                    new OAT\Property(property: 'age_group', type: 'string', enum: ['adult', 'child'], required: ['age_group']),
                                    new OAT\Property(property: 'tshirt_required', type: 'boolean', nullable: true),
                                    new OAT\Property(property: 'tshirt_size', type: 'string', nullable: true),
                                ],
                                type: 'object'
                            ),
                            nullable: true
                        ),
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
                            new OAT\Property(property: 'ulid', type: 'string'),
                            new OAT\Property(property: 'status', type: 'string'),
                            new OAT\Property(property: 'special_notes', type: 'string', nullable: true),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Not owned by the authenticated attendee, or not in an editable status'),
            new OAT\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateRegistrationRequest $request, Registration $registration): RegistrationResource
    {
        $registration->update($request->only(['special_notes']));

        if ($request->has('guests')) {
            DB::transaction(function () use ($registration, $request): void {
                $registration->guests()->delete();

                /** @var array<int, array<string, mixed>> $guests */
                $guests = $request->input('guests', []);

                if (count($guests) > 0) {
                    $registration->guests()->createMany($guests);
                }
            });
        }

        $registration->load(['guests', 'ticketType', 'eventSession']);

        return new RegistrationResource($registration);
    }

    #[OAT\Post(
        path: '/attendee/registrations/{registration}/cancel',
        summary: 'Cancel an owned registration that is still pending payment',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
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
                description: 'Registration cancelled',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'ulid', type: 'string'),
                            new OAT\Property(property: 'status', type: 'string'),
                            new OAT\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
                        ]
                    )
                )
            ),
            new OAT\Response(
                response: 400,
                description: 'Registration is not in pending_payment status',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(
                response: 404,
                description: 'Registration not found, or not owned by the authenticated attendee',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function cancel(Request $request, Registration $registration): RegistrationResource|JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        if ($registration->attendee_id !== $attendee->id) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'Registration not found.',
            ], 404);
        }

        if ($registration->status !== 'pending_payment') {
            return response()->json([
                'code' => 'INVALID_STATUS',
                'message' => 'Only pending_payment registrations can be cancelled.',
            ], 400);
        }

        DB::transaction(function () use ($registration): void {
            $registration->transitionTo('cancelled');

            $registration->payments()->where('status', 'pending')->update(['status' => 'cancelled']);

            DB::update(
                'UPDATE ticket_types SET quantity_reserved = GREATEST(0, quantity_reserved - 1) WHERE id = ?',
                [$registration->ticket_type_id]
            );
        });

        return new RegistrationResource($registration);
    }
}
