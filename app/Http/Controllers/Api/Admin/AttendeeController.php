<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Registration\Actions\ExportAttendees;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Support\AttendeeListFilters;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportAttendeesRequest;
use App\Http\Requests\Admin\UpdateAttendeeRequest;
use App\Http\Resources\AttendeeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Attendees')]
class AttendeeController extends Controller
{
    #[OAT\Get(
        path: '/admin/attendees',
        summary: 'List attendees with search and filters',
        tags: ['Attendees'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'search',
                in: 'query',
                description: 'Search by full name, mobile, or email',
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\Parameter(
                name: 'participant_type',
                in: 'query',
                description: 'Filter by participant type',
                schema: new OAT\Schema(type: 'string', enum: ['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'other'])
            ),
            new OAT\Parameter(
                name: 'ssc_batch_year',
                in: 'query',
                description: 'Filter by SSC batch year',
                schema: new OAT\Schema(type: 'integer')
            ),
            new OAT\Parameter(
                name: 'sort',
                in: 'query',
                description: 'Column to order by. Unknown values fall back to the default.',
                schema: new OAT\Schema(type: 'string', default: 'created_at', enum: ['full_name', 'participant_type', 'ssc_batch_year', 'is_verified', 'created_at'])
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
                description: 'Paginated list of attendees',
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
                                        new OAT\Property(property: 'full_name', type: 'string'),
                                        new OAT\Property(property: 'full_name_bn', type: 'string', nullable: true),
                                        new OAT\Property(property: 'father_name', type: 'string', nullable: true),
                                        new OAT\Property(property: 'mobile', type: 'string'),
                                        new OAT\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                                        new OAT\Property(property: 'gender', type: 'string', nullable: true),
                                        new OAT\Property(property: 'date_of_birth', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'occupation', type: 'string', nullable: true),
                                        new OAT\Property(property: 'designation', type: 'string', nullable: true),
                                        new OAT\Property(property: 'organization', type: 'string', nullable: true),
                                        new OAT\Property(property: 'participant_type', type: 'string'),
                                        new OAT\Property(property: 'ssc_batch_year', type: 'integer', nullable: true),
                                        new OAT\Property(property: 'current_class', type: 'string', nullable: true),
                                        new OAT\Property(property: 'tshirt_required', type: 'boolean'),
                                        new OAT\Property(property: 'tshirt_size', type: 'string', nullable: true),
                                        new OAT\Property(property: 'address_district', type: 'string', nullable: true),
                                        new OAT\Property(property: 'current_address', type: 'string', nullable: true),
                                        new OAT\Property(property: 'country', type: 'string', nullable: true),
                                        new OAT\Property(property: 'blood_group', type: 'string', nullable: true),
                                        new OAT\Property(property: 'emergency_contact_name', type: 'string', nullable: true),
                                        new OAT\Property(property: 'emergency_contact_phone', type: 'string', nullable: true),
                                        new OAT\Property(property: 'notes', type: 'string', nullable: true),
                                        new OAT\Property(property: 'is_verified', type: 'boolean'),
                                        new OAT\Property(property: 'profile_photo_url', type: 'string', nullable: true),
                                        new OAT\Property(property: 'profile_photo_thumb_url', type: 'string', nullable: true),
                                        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
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
            new OAT\Response(response: 403, description: 'Missing attendee.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('attendee.view_any'), Response::HTTP_FORBIDDEN);

        // Filtering and ordering live in AttendeeListFilters so the export
        // endpoint below selects exactly the rows this list shows — an export
        // that quietly disagrees with the screen it was launched from is worse
        // than none at all.
        $query = AttendeeListFilters::apply(
            Attendee::query()->with(['profilePhoto.thumbnail']),
            $request->only(['search', 'participant_type', 'ssc_batch_year', 'sort', 'direction']),
        );

        $perPage = min((int) $request->input('per_page', 15), 100);

        return AttendeeResource::collection($query->paginate($perPage));
    }

    #[OAT\Get(
        path: '/admin/attendees/export',
        summary: 'Download the filtered attendee list as a spreadsheet or PDF',
        description: 'Returns a binary file, not JSON. Each row carries the attendee\'s profile photo, name, '
            .'father\'s name, current address, occupation, organization and mobile number. The filter parameters '
            .'are identical to GET /admin/attendees, so an export always contains exactly the rows that list shows. '
            .'Generated synchronously; a filter set selecting more rows than the format\'s configured ceiling is '
            .'refused with 422 export_too_large rather than run to a timeout.',
        tags: ['Attendees'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'format',
                in: 'query',
                required: true,
                description: 'File format to generate',
                schema: new OAT\Schema(type: 'string', enum: ['xlsx', 'pdf'])
            ),
            new OAT\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Partial match against name, mobile, or email',
                schema: new OAT\Schema(type: 'string', maxLength: 150)
            ),
            new OAT\Parameter(
                name: 'participant_type',
                in: 'query',
                required: false,
                schema: new OAT\Schema(type: 'string', enum: AttendeeListFilters::PARTICIPANT_TYPES)
            ),
            new OAT\Parameter(
                name: 'ssc_batch_year',
                in: 'query',
                required: false,
                schema: new OAT\Schema(type: 'integer')
            ),
            new OAT\Parameter(
                name: 'sort',
                in: 'query',
                required: false,
                description: 'Column to order by — identical to GET /admin/attendees, so the file matches the screen.',
                schema: new OAT\Schema(type: 'string', default: 'created_at', enum: ['full_name', 'participant_type', 'ssc_batch_year', 'is_verified', 'created_at'])
            ),
            new OAT\Parameter(
                name: 'direction',
                in: 'query',
                required: false,
                schema: new OAT\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc'])
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The generated file, as an attachment',
                content: [
                    new OAT\MediaType(
                        mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        schema: new OAT\Schema(type: 'string', format: 'binary')
                    ),
                    new OAT\MediaType(
                        mediaType: 'application/pdf',
                        schema: new OAT\Schema(type: 'string', format: 'binary')
                    ),
                ]
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing attendee.export permission'),
            new OAT\Response(response: 422, description: 'Validation error, or export_too_large for the selected filters'),
        ]
    )]
    public function export(ExportAttendeesRequest $request, ExportAttendees $action): Response
    {
        /** @var 'xlsx'|'pdf' $format */
        $format = $request->validated('format');

        $user = $request->user();

        return $action->execute(
            filters: $request->filters(),
            format: $format,
            actor: $user instanceof User ? $user : null,
            ipAddress: $request->ip(),
            requestId: $request->header('X-Request-Id'),
        )->response();
    }

    #[OAT\Get(
        path: '/admin/attendees/{attendee}',
        summary: 'Get a single attendee',
        tags: ['Attendees'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'attendee',
                in: 'path',
                required: true,
                description: 'Attendee ULID',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Attendee detail, including registrations',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'full_name', type: 'string'),
                                    new OAT\Property(property: 'full_name_bn', type: 'string', nullable: true),
                                    new OAT\Property(property: 'father_name', type: 'string', nullable: true),
                                    new OAT\Property(property: 'mobile', type: 'string'),
                                    new OAT\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                                    new OAT\Property(property: 'gender', type: 'string', nullable: true),
                                    new OAT\Property(property: 'date_of_birth', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'occupation', type: 'string', nullable: true),
                                    new OAT\Property(property: 'designation', type: 'string', nullable: true),
                                    new OAT\Property(property: 'organization', type: 'string', nullable: true),
                                    new OAT\Property(property: 'participant_type', type: 'string'),
                                    new OAT\Property(property: 'ssc_batch_year', type: 'integer', nullable: true),
                                    new OAT\Property(property: 'current_class', type: 'string', nullable: true),
                                    new OAT\Property(property: 'tshirt_required', type: 'boolean'),
                                    new OAT\Property(property: 'tshirt_size', type: 'string', nullable: true),
                                    new OAT\Property(property: 'address_district', type: 'string', nullable: true),
                                    new OAT\Property(property: 'current_address', type: 'string', nullable: true),
                                    new OAT\Property(property: 'country', type: 'string', nullable: true),
                                    new OAT\Property(property: 'blood_group', type: 'string', nullable: true),
                                    new OAT\Property(property: 'emergency_contact_name', type: 'string', nullable: true),
                                    new OAT\Property(property: 'emergency_contact_phone', type: 'string', nullable: true),
                                    new OAT\Property(property: 'notes', type: 'string', nullable: true),
                                    new OAT\Property(property: 'is_verified', type: 'boolean'),
                                    new OAT\Property(property: 'profile_photo_url', type: 'string', nullable: true),
                                    new OAT\Property(property: 'profile_photo_thumb_url', type: 'string', nullable: true),
                                    new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing attendee.view permission'),
            new OAT\Response(response: 404, description: 'Attendee not found'),
        ]
    )]
    public function show(Request $request, Attendee $attendee): AttendeeResource
    {
        abort_unless((bool) $request->user()?->can('attendee.view'), Response::HTTP_FORBIDDEN);

        $attendee->load([
            'profilePhoto.thumbnail',
            'registrations.ticketType',
            'registrations.payments',
            'registrations.tickets',
        ]);

        return new AttendeeResource($attendee);
    }

    #[OAT\Patch(
        path: '/admin/attendees/{attendee}',
        summary: 'Update an attendee record',
        tags: ['Attendees'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'attendee',
                in: 'path',
                required: true,
                description: 'Attendee ULID',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'full_name', type: 'string', maxLength: 150),
                        new OAT\Property(property: 'full_name_bn', type: 'string', nullable: true, maxLength: 150),
                        new OAT\Property(property: 'father_name', type: 'string', nullable: true, maxLength: 150),
                        new OAT\Property(property: 'occupation', type: 'string', nullable: true, maxLength: 100),
                        new OAT\Property(property: 'current_address', type: 'string', nullable: true, maxLength: 255),
                        new OAT\Property(property: 'mobile', type: 'string', maxLength: 20),
                        new OAT\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 254),
                        new OAT\Property(
                            property: 'participant_type',
                            type: 'string',
                            enum: ['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'other']
                        ),
                        new OAT\Property(property: 'ssc_batch_year', type: 'integer', nullable: true),
                        new OAT\Property(property: 'is_verified', type: 'boolean'),
                        new OAT\Property(property: 'notes', type: 'string', nullable: true, maxLength: 1000),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Attendee updated',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'full_name', type: 'string'),
                                    new OAT\Property(property: 'mobile', type: 'string'),
                                    new OAT\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                                    new OAT\Property(property: 'participant_type', type: 'string'),
                                    new OAT\Property(property: 'is_verified', type: 'boolean'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing attendee.update permission'),
            new OAT\Response(response: 404, description: 'Attendee not found'),
            new OAT\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateAttendeeRequest $request, Attendee $attendee): AttendeeResource
    {
        $oldData = $attendee->toArray();
        $attendee->update($request->validated());

        ActivityLog::create([
            'log_name' => 'attendee',
            'event' => 'updated',
            'description' => 'Attendee updated',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $attendee->getMorphClass(),
            'subject_id' => $attendee->id,
            'properties' => ['old' => $oldData, 'new' => $attendee->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
        ]);

        return new AttendeeResource($attendee->refresh());
    }

    #[OAT\Delete(
        path: '/admin/attendees/{attendee}',
        summary: 'Delete an attendee (only when no active registrations or issued tickets)',
        tags: ['Attendees'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'attendee',
                in: 'path',
                required: true,
                description: 'Attendee ULID',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(response: 204, description: 'Attendee deleted'),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing attendee.delete permission'),
            new OAT\Response(response: 404, description: 'Attendee not found'),
            new OAT\Response(
                response: 422,
                description: 'Attendee has active/paid registrations or issued tickets and cannot be deleted',
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
    public function destroy(Request $request, Attendee $attendee): JsonResponse|Response
    {
        abort_unless((bool) $request->user()?->can('attendee.delete'), Response::HTTP_FORBIDDEN);

        $hasActiveRegistrations = $attendee->registrations()
            ->whereIn('status', ['paid', 'confirmed'])
            ->exists();

        $hasIssuedTickets = $attendee->registrations()
            ->whereHas('tickets')
            ->exists();

        if ($hasActiveRegistrations || $hasIssuedTickets) {
            return response()->json([
                'code' => 'deletion_prevented',
                'message' => 'Cannot delete attendee with active/paid registrations or issued tickets.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attendee->delete();

        ActivityLog::create([
            'log_name' => 'attendee',
            'event' => 'deleted',
            'description' => 'Attendee soft deleted',
            'causer_type' => $request->user()->getMorphClass(),
            'causer_id' => $request->user()->id,
            'subject_type' => $attendee->getMorphClass(),
            'subject_id' => $attendee->id,
            'properties' => ['attendee_ulid' => $attendee->ulid],
            'ip_address' => $request->ip(),
            'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
        ]);

        return response()->noContent();
    }
}
