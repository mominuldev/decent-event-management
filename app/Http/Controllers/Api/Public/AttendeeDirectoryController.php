<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Registration\Support\AttendeeListFilters;
use App\Domain\Registration\Support\PublicAttendeeDirectory;
use App\Http\Concerns\RespondsWithEtag;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PublicAttendeeResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Public')]
class AttendeeDirectoryController extends Controller
{
    use RespondsWithEtag;

    /** Cards per page when the caller does not say. */
    private const DEFAULT_PER_PAGE = 12;

    /**
     * Hard ceiling on page size. A public, unauthenticated endpoint must not
     * let a caller ask for the whole 20,000-row directory in one request —
     * that is a free bulk export of the roster.
     */
    private const MAX_PER_PAGE = 48;

    #[OAT\Get(
        path: '/public/attendees',
        summary: 'Public directory of attendees whose registration has succeeded',
        description: 'Lists only registrations in `paid` or `confirmed` status — an unpaid, cancelled, refunded or expired registration never appears. Contact details, guest names and money are deliberately not exposed; the badge photo is published as a signed thumbnail URL.',
        tags: ['Public'],
        parameters: [
            new OAT\Parameter(name: 'search', in: 'query', description: 'Free text over name, occupation, designation, organization, district, class, and batch year', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'participant_type', in: 'query', schema: new OAT\Schema(type: 'string', enum: AttendeeListFilters::PARTICIPANT_TYPES)),
            new OAT\Parameter(name: 'batch_year', in: 'query', description: 'Exact SSC batch year', schema: new OAT\Schema(type: 'integer')),
            new OAT\Parameter(name: 'batch_from', in: 'query', description: 'Inclusive lower bound on batch year — a decade filter sends a range, not a decade name', schema: new OAT\Schema(type: 'integer')),
            new OAT\Parameter(name: 'batch_to', in: 'query', description: 'Inclusive upper bound on batch year', schema: new OAT\Schema(type: 'integer')),
            new OAT\Parameter(name: 'has_guests', in: 'query', description: 'Restrict to registrations that brought family, or to lone registrants', schema: new OAT\Schema(type: 'string', enum: ['yes', 'no'])),
            new OAT\Parameter(name: 'sort', in: 'query', description: 'Unknown values fall back to the default.', schema: new OAT\Schema(type: 'string', default: 'batch_asc', enum: ['batch_asc', 'batch_desc', 'name_asc', 'recent'])),
            new OAT\Parameter(name: 'per_page', in: 'query', description: 'Cards per page, capped at 48', schema: new OAT\Schema(type: 'integer', default: 12)),
            new OAT\Parameter(name: 'page', in: 'query', schema: new OAT\Schema(type: 'integer', default: 1)),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Paginated directory page, plus whole-directory counters and the batch years worth offering as filters',
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
                                        new OAT\Property(property: 'participant_type', type: 'string'),
                                        new OAT\Property(property: 'ssc_batch_year', type: 'integer', nullable: true),
                                        new OAT\Property(property: 'current_class', type: 'string', nullable: true),
                                        new OAT\Property(property: 'occupation', type: 'string', nullable: true),
                                        new OAT\Property(property: 'designation', type: 'string', nullable: true),
                                        new OAT\Property(property: 'organization', type: 'string', nullable: true),
                                        new OAT\Property(property: 'address_district', type: 'string', nullable: true),
                                        new OAT\Property(property: 'country', type: 'string', nullable: true),
                                        new OAT\Property(property: 'is_verified', type: 'boolean'),
                                        new OAT\Property(property: 'profile_photo_url', type: 'string', nullable: true, description: 'Signed URL to the 128px thumbnail of the badge photo, or null when the attendee never uploaded one'),
                                        new OAT\Property(property: 'avatar_variant', type: 'string', enum: ['male', 'female', 'neutral'], description: 'Which placeholder to draw when there is no photo. A derived hint, not the private `gender` column — anything not plainly male or female is `neutral`.'),
                                        new OAT\Property(property: 'participation_type', type: 'string'),
                                        new OAT\Property(property: 'adults_count', type: 'integer'),
                                        new OAT\Property(property: 'children_count', type: 'integer'),
                                        new OAT\Property(property: 'infants_count', type: 'integer'),
                                        new OAT\Property(property: 'guests_count', type: 'integer'),
                                        new OAT\Property(property: 'ticket_type_name', type: 'string', nullable: true),
                                        new OAT\Property(property: 'ticket_type_name_bn', type: 'string', nullable: true),
                                        new OAT\Property(property: 'registered_at', type: 'string', format: 'date-time'),
                                    ],
                                    type: 'object'
                                )
                            ),
                            new OAT\Property(property: 'links', type: 'object'),
                            new OAT\Property(
                                property: 'meta',
                                properties: [
                                    new OAT\Property(
                                        property: 'stats',
                                        description: 'Counters over the whole directory, unaffected by the filters on this request',
                                        properties: [
                                            new OAT\Property(property: 'total_registered', type: 'integer'),
                                            new OAT\Property(property: 'total_alumni', type: 'integer'),
                                            new OAT\Property(property: 'total_students', type: 'integer'),
                                            new OAT\Property(property: 'total_teachers_staff', type: 'integer'),
                                            new OAT\Property(property: 'total_guests', type: 'integer'),
                                            new OAT\Property(property: 'total_batches', type: 'integer'),
                                        ],
                                        type: 'object'
                                    ),
                                    new OAT\Property(property: 'available_batches', type: 'array', items: new OAT\Items(type: 'integer')),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 304, description: 'Not modified'),
        ]
    )]
    public function index(Request $request): Response
    {
        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = PublicAttendeeDirectory::apply(
            PublicAttendeeDirectory::query(),
            $request->query(),
        );

        $registrations = $query
            // `profilePhoto.thumbnail` is loaded here, not left to
            // `smallest()` to lazy-load, or every card on the page costs two
            // extra queries.
            ->with(['attendee.profilePhoto.thumbnail', 'ticketType'])
            ->withCount('guests')
            ->paginate($perPage)
            ->withQueryString();

        $response = PublicAttendeeResource::collection($registrations)
            ->additional([
                'meta' => [
                    'stats' => PublicAttendeeDirectory::summary(),
                    'available_batches' => PublicAttendeeDirectory::availableBatches(),
                ],
            ])
            ->response($request);

        return $this->withPublicCache($request, $response);
    }
}
