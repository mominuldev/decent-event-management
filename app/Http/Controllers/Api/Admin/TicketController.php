<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Notification\Listeners\QueueTicketDeliveredNotification;
use App\Domain\Notification\Support\ChannelKillSwitch;
use App\Domain\Notification\Support\SmsGatewayConfig;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Actions\ResendTicketNotification;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Support\TicketListFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkResendTicketsRequest;
use App\Http\Requests\Admin\ResendTicketRequest;
use App\Http\Requests\Admin\VoidTicketRequest;
use App\Http\Resources\TicketResource;
use App\Jobs\ResendTicketNotificationsJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Tickets')]
class TicketController extends Controller
{
    #[OAT\Get(
        path: '/admin/tickets',
        summary: 'List tickets with optional filters',
        tags: ['Tickets'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\QueryParameter(
                name: 'status',
                description: 'Filter by ticket status',
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\QueryParameter(
                name: 'ticket_type_id',
                description: 'Filter by ticket type ID',
                schema: new OAT\Schema(type: 'integer')
            ),
            new OAT\QueryParameter(
                name: 'search',
                description: 'Search by ticket number or holder name',
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\QueryParameter(
                name: 'sort',
                description: 'Column to order by. Unknown values fall back to the default.',
                schema: new OAT\Schema(type: 'string', default: 'created_at', enum: ['ticket_number', 'holder_name', 'status', 'admitted_count', 'created_at'])
            ),
            new OAT\QueryParameter(
                name: 'direction',
                description: 'Order direction. Defaults to newest first.',
                schema: new OAT\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc'])
            ),
            new OAT\QueryParameter(
                name: 'per_page',
                description: 'Results per page, capped at 100',
                schema: new OAT\Schema(type: 'integer', default: 20)
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Paginated list of tickets',
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
                                        new OAT\Property(property: 'ticket_number', type: 'string'),
                                        new OAT\Property(property: 'status', type: 'string'),
                                        new OAT\Property(property: 'admits_total', type: 'integer'),
                                        new OAT\Property(property: 'admitted_count', type: 'integer'),
                                        new OAT\Property(property: 'price_paid_paisa', type: 'integer', description: 'Amount in paisa (1 BDT = 100 paisa)'),
                                        new OAT\Property(property: 'currency', type: 'string'),
                                        new OAT\Property(property: 'holder_name', type: 'string'),
                                        new OAT\Property(property: 'holder_batch_year', type: 'integer', nullable: true),
                                        new OAT\Property(property: 'holder_type_label', type: 'string', nullable: true),
                                        new OAT\Property(property: 'issued_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'voided_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'void_reason', type: 'string', nullable: true),
                                        new OAT\Property(property: 'manifest_version', type: 'integer'),
                                        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                                    ],
                                    type: 'object'
                                )
                            ),
                            new OAT\Property(
                                property: 'meta',
                                properties: [
                                    new OAT\Property(property: 'current_page', type: 'integer'),
                                    new OAT\Property(property: 'per_page', type: 'integer'),
                                    new OAT\Property(property: 'total', type: 'integer'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing ticket.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('ticket.view_any'), Response::HTTP_FORBIDDEN);

        // Filters live in TicketListFilters, shared with the bulk-resend
        // preview and the bulk resend itself — a bulk action that selects a
        // different set than the screen it was launched from is worse than
        // no bulk action.
        $query = TicketListFilters::apply(
            Ticket::query()->with(['ticketType']),
            (array) $request->query(),
        );

        $perPage = min((int) $request->query('per_page', 20), 100);

        return TicketResource::collection($query->paginate($perPage));
    }

    #[OAT\Get(
        path: '/admin/tickets/{ticket}',
        summary: 'Get a single ticket by ULID',
        tags: ['Tickets'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'ticket', description: 'Ticket ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Ticket details',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'ulid', type: 'string'),
                            new OAT\Property(property: 'ticket_number', type: 'string'),
                            new OAT\Property(property: 'status', type: 'string'),
                            new OAT\Property(property: 'admits_total', type: 'integer'),
                            new OAT\Property(property: 'admitted_count', type: 'integer'),
                            new OAT\Property(property: 'price_paid_paisa', type: 'integer', description: 'Amount in paisa (1 BDT = 100 paisa)'),
                            new OAT\Property(property: 'currency', type: 'string'),
                            new OAT\Property(property: 'holder_name', type: 'string'),
                            new OAT\Property(property: 'holder_batch_year', type: 'integer', nullable: true),
                            new OAT\Property(property: 'holder_type_label', type: 'string', nullable: true),
                            new OAT\Property(property: 'issued_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'voided_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'void_reason', type: 'string', nullable: true),
                            new OAT\Property(property: 'first_admitted_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'last_admitted_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'manifest_version', type: 'integer'),
                            new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'ticket_type', type: 'object', description: 'Ticket type this ticket belongs to (see Ticket Types schema)'),
                            new OAT\Property(property: 'qr_code_payload', type: 'string', nullable: true, description: 'Signed QR payload, when loaded'),
                            new OAT\Property(property: 'qr_code_image_url', type: 'string', nullable: true, description: 'Short-TTL signed URL for the rendered QR PNG, when generated'),
                            new OAT\Property(property: 'replaces', type: 'object', nullable: true, description: 'The ticket this one replaced via reissue, if any'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing ticket.view permission'),
            new OAT\Response(response: 404, description: 'Ticket not found'),
        ]
    )]
    public function show(Request $request, Ticket $ticket): TicketResource
    {
        abort_unless((bool) $request->user()?->can('ticket.view'), Response::HTTP_FORBIDDEN);

        $ticket->load(['registration', 'attendee', 'ticketType', 'qrCode.image', 'checkIns']);

        return new TicketResource($ticket);
    }

    #[OAT\Post(
        path: '/admin/tickets/{ticket}/void',
        summary: 'Void an issued ticket',
        description: 'Tickets are immutable once issued — voiding transitions status via the state machine and deactivates the QR code rather than deleting the record.',
        tags: ['Tickets'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'ticket', description: 'Ticket ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'void_reason',
                            type: 'string',
                            description: 'Reason for voiding the ticket',
                            required: ['void_reason']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Ticket voided successfully',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'data', type: 'object', description: 'The voided ticket (see GET /admin/tickets/{ticket} for shape)'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing ticket.void permission'),
            new OAT\Response(
                response: 422,
                description: 'Ticket is already voided',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'void_failed'),
                            new OAT\Property(property: 'message', type: 'string'),
                            new OAT\Property(property: 'request_id', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function void(VoidTicketRequest $request, Ticket $ticket): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $ticket): void {
                if ($ticket->status === 'voided') {
                    throw new InvalidArgumentException('Ticket is already voided.');
                }

                $ticket->transitionTo('voided');
                $ticket->voided_at = now();
                $ticket->void_reason = $request->validated('void_reason');
                /** @var User $user */
                $user = $request->user();
                $ticket->voided_by_user_id = max(0, (int) $user->id);
                $ticket->manifest_version++;
                $ticket->save();

                if ($ticket->qrCode !== null) {
                    $ticket->qrCode->update(['is_active' => false]);
                }

                ActivityLog::create([
                    'log_name' => 'ticket',
                    'event' => 'voided',
                    'description' => "Voided ticket {$ticket->ticket_number}",
                    'causer_type' => $user->getMorphClass(),
                    'causer_id' => $user->id,
                    'subject_type' => $ticket->getMorphClass(),
                    'subject_id' => $ticket->id,
                    'properties' => [
                        'reason' => $request->validated('void_reason'),
                    ],
                    'ip_address' => $request->ip(),
                    'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
                ]);
            });

            return response()->json([
                'data' => new TicketResource($ticket->refresh()->load(['ticketType', 'qrCode.image'])),
                'message' => 'Ticket voided successfully.',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 'void_failed',
                'message' => $e->getMessage(),
                'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            ], 422);
        }
    }

    #[OAT\Post(
        path: '/admin/tickets/{ticket}/reissue',
        summary: 'Void a ticket and issue a replacement in its place',
        description: 'Corrections to an issued ticket are always void + reissue, never edit — the new ticket links back via replaces_ticket_id.',
        tags: ['Tickets'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'ticket', description: 'Ticket ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Ticket reissued successfully',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'data', type: 'object', description: 'The newly issued replacement ticket (see GET /admin/tickets/{ticket} for shape)'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing ticket.reissue permission'),
            new OAT\Response(
                response: 422,
                description: 'Ticket is already voided and cannot be reissued, or is not linked to a registration',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'reissue_failed'),
                            new OAT\Property(property: 'message', type: 'string'),
                            new OAT\Property(property: 'request_id', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function reissue(Request $request, Ticket $ticket, IssueTicket $action): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('ticket.reissue'), Response::HTTP_FORBIDDEN);

        try {
            $newTicket = DB::transaction(function () use ($request, $ticket, $action): Ticket {
                if ($ticket->status === 'voided') {
                    throw new InvalidArgumentException('Cannot reissue an already voided ticket.');
                }

                $ticket->transitionTo('voided');
                $ticket->voided_at = now();
                $ticket->void_reason = 'Reissued';
                /** @var User $user */
                $user = $request->user();
                $ticket->voided_by_user_id = max(0, (int) $user->id);
                $ticket->manifest_version++;
                $ticket->save();

                if ($ticket->qrCode !== null) {
                    $ticket->qrCode->update(['is_active' => false]);
                }

                $registration = $ticket->registration;
                if ($registration === null) {
                    throw new InvalidArgumentException('Ticket is not linked to any registration.');
                }

                $newTicket = $action->execute($registration);
                $newTicket->replaces_ticket_id = max(0, (int) $ticket->id);
                $newTicket->save();

                ActivityLog::create([
                    'log_name' => 'ticket',
                    'event' => 'reissued',
                    'description' => "Reissued ticket {$ticket->ticket_number} as {$newTicket->ticket_number}",
                    'causer_type' => $user->getMorphClass(),
                    'causer_id' => $user->id,
                    'subject_type' => $ticket->getMorphClass(),
                    'subject_id' => $ticket->id,
                    'properties' => [
                        'new_ticket_ulid' => $newTicket->ulid,
                        'new_ticket_number' => $newTicket->ticket_number,
                    ],
                    'ip_address' => $request->ip(),
                    'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
                ]);

                return $newTicket;
            });

            return response()->json([
                'data' => new TicketResource($newTicket->load(['ticketType', 'qrCode.image'])),
                'message' => 'Ticket reissued successfully.',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 'reissue_failed',
                'message' => $e->getMessage(),
                'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            ], 422);
        }
    }

    #[OAT\Post(
        path: '/admin/tickets/{ticket}/resend',
        summary: "Resend a ticket's confirmation to its holder by email and/or SMS",
        description: 'Composes the confirmation afresh from the ticket rather than replaying a stored '
            .'message, so it works whether or not the original was ever delivered — or ever written. '
            .'Requires an Idempotency-Key: a resent SMS is billed, so a double-tapped button must not '
            .'send twice.',
        tags: ['Tickets'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'ticket', description: 'Ticket ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\HeaderParameter(name: 'Idempotency-Key', required: true, schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['channels'],
                    properties: [
                        new OAT\Property(
                            property: 'channels',
                            type: 'array',
                            items: new OAT\Items(type: 'string', enum: ['email', 'sms']),
                            description: 'No default — SMS is billed per segment, so the choice is explicit every time.'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Queued, with the outcome of each requested channel',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(
                                        property: 'outcomes',
                                        type: 'object',
                                        description: 'channel => queued | no_recipient | no_template | duplicate'
                                    ),
                                    new OAT\Property(
                                        property: 'channels_disabled',
                                        type: 'array',
                                        items: new OAT\Items(type: 'string'),
                                        description: 'Requested channels whose kill switch is currently off. The row is '
                                            .'still queued — the switch is enforced at send time — but it will be cancelled.'
                                    ),
                                ],
                                type: 'object'
                            ),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing notification.resend permission'),
            new OAT\Response(response: 422, description: 'Ticket is voided or refunded, or has no attendee'),
        ]
    )]
    public function resend(ResendTicketRequest $request, Ticket $ticket, ResendTicketNotification $action): JsonResponse
    {
        /** @var array<int, string> $channels */
        $channels = $request->validated('channels');
        /** @var User $user */
        $user = $request->user();

        try {
            $outcomes = $action->execute(
                ticket: $ticket,
                channels: $channels,
                resentBy: $user,
                ip: $request->ip(),
                requestId: self::requestId($request),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 'resend_failed',
                'message' => $e->getMessage(),
                'request_id' => self::requestId($request),
            ], 422);
        }

        return response()->json([
            'data' => [
                'outcomes' => $outcomes,
                'channels_disabled' => app(ChannelKillSwitch::class)->disabledAmong($channels),
            ],
            'message' => 'Ticket confirmation queued.',
        ]);
    }

    #[OAT\Get(
        path: '/admin/tickets/resend-preview',
        summary: 'How many tickets a bulk resend would reach, and what the SMS would cost',
        description: 'Read-only. Takes the same filters as the ticket list and narrows them to tickets '
            .'whose QR still admits someone. The count it returns is what the caller must echo back as '
            .'`expected_count` to confirm the send.',
        tags: ['Tickets'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\QueryParameter(name: 'status', schema: new OAT\Schema(type: 'string')),
            new OAT\QueryParameter(name: 'ticket_type_id', schema: new OAT\Schema(type: 'integer')),
            new OAT\QueryParameter(name: 'search', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Recipient count and cost estimate',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'tickets', type: 'integer'),
                                    new OAT\Property(property: 'with_email', type: 'integer'),
                                    new OAT\Property(property: 'with_mobile', type: 'integer'),
                                    new OAT\Property(property: 'sms_segments_each', type: 'integer'),
                                    new OAT\Property(property: 'sms_cost_paisa_total', type: 'integer'),
                                    new OAT\Property(property: 'channels_disabled', type: 'array', items: new OAT\Items(type: 'string')),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing notification.send_broadcast permission'),
        ]
    )]
    public function resendPreview(Request $request, QueueNotification $queueNotification): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('notification.send_broadcast'), Response::HTTP_FORBIDDEN);

        $filters = (array) $request->query();
        $base = ResendTicketNotificationsJob::query($filters);

        // Three counts rather than one, because "how many will actually get
        // a message" is a different number from "how many tickets match":
        // a holder with no address silently drops out of an email send, and
        // the operator should see that here rather than afterwards in the
        // delivery log.
        //
        // Email is the count that moves. `attendees.mobile` is NOT NULL, so
        // `with_mobile` is normally just the ticket count — it is kept
        // because it is what the SMS cost is computed from, and because a
        // blank string is not NULL and would otherwise be billed for.
        $tickets = (clone $base)->count();
        $withEmail = (clone $base)->whereHas('attendee', fn (Builder $q) => $q->whereNotNull('email')->where('email', '!=', ''))->count();
        $withMobile = (clone $base)->whereHas('attendee', fn (Builder $q) => $q->whereNotNull('mobile')->where('mobile', '!=', ''))->count();

        $segments = $this->ticketSmsSegments($queueNotification);
        $costPerSegment = max(0, (int) app(SmsGatewayConfig::class)->get('cost_paisa_per_segment', '0'));

        return response()->json([
            'data' => [
                'tickets' => $tickets,
                'with_email' => $withEmail,
                'with_mobile' => $withMobile,
                'sms_segments_each' => $segments,
                // Costed against the holders who actually have a number, not
                // against the ticket count.
                'sms_cost_paisa_total' => $segments * $costPerSegment * $withMobile,
                'channels_disabled' => app(ChannelKillSwitch::class)->disabledAmong(ResendTicketNotification::CHANNELS),
            ],
        ]);
    }

    #[OAT\Post(
        path: '/admin/tickets/resend-all',
        summary: 'Resend the ticket confirmation to every ticket matching a filter set',
        description: 'Queues a background fan-out on the `reports` lane; the response returns immediately '
            .'with the number of tickets it will walk. Requires `expected_count` to match what '
            .'`/admin/tickets/resend-preview` currently reports, and an Idempotency-Key.',
        tags: ['Tickets'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\HeaderParameter(name: 'Idempotency-Key', required: true, schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['channels', 'expected_count'],
                    properties: [
                        new OAT\Property(property: 'channels', type: 'array', items: new OAT\Items(type: 'string', enum: ['email', 'sms'])),
                        new OAT\Property(property: 'expected_count', type: 'integer', description: 'The count the preview returned.'),
                        new OAT\Property(property: 'status', type: 'string', nullable: true),
                        new OAT\Property(property: 'ticket_type_id', type: 'integer', nullable: true),
                        new OAT\Property(property: 'search', type: 'string', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 202, description: 'Fan-out queued'),
            new OAT\Response(response: 403, description: 'Missing notification.send_broadcast permission'),
            new OAT\Response(response: 409, description: 'The roster changed since the preview — expected_count no longer matches'),
        ]
    )]
    public function resendAll(BulkResendTicketsRequest $request): JsonResponse
    {
        /** @var array<int, string> $channels */
        $channels = $request->validated('channels');
        /** @var User $user */
        $user = $request->user();

        $filters = $request->filters();
        $tickets = ResendTicketNotificationsJob::query($filters)->count();

        // Refuse rather than silently send to more people than were agreed
        // to. The window is small, but the cost of getting it wrong is a
        // message — and an SMS charge — for every ticket issued while the
        // confirmation dialog sat open.
        if ($tickets !== (int) $request->validated('expected_count')) {
            return response()->json([
                'code' => 'recipient_count_changed',
                'message' => "This now matches {$tickets} ticket(s), not "
                    .$request->validated('expected_count').'. Review the count and confirm again.',
                'request_id' => self::requestId($request),
            ], 409);
        }

        ResendTicketNotificationsJob::dispatch(
            filters: $filters,
            channels: $channels,
            requestedByUserId: (int) $user->id,
            ip: $request->ip(),
            requestId: self::requestId($request),
        );

        return response()->json([
            'data' => [
                'tickets' => $tickets,
                'channels' => $channels,
                'channels_disabled' => app(ChannelKillSwitch::class)->disabledAmong($channels),
            ],
            'message' => "Queued a resend to {$tickets} ticket(s). Delivery progress appears in the notifications log.",
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Segments one ticket SMS costs.
     *
     * Measured from **the row the outbox would actually render**, resolved
     * through `QueueNotification` rather than looked up here — a plain
     * "active sms template for this key" query returns whichever locale
     * the database hands back first, which on a seeded database is the
     * Bangla one at two segments where `notifications.locales.sms` is
     * English at one. That is a 2x over-estimate, in the direction that
     * makes an affordable send look unaffordable.
     *
     * Placeholders are substituted for a representative width before
     * measuring: `{` and `}` are not in the GSM-7 alphabet, so measuring a
     * raw template body reports Unicode and over-counts by 3x again.
     */
    private function ticketSmsSegments(QueueNotification $queueNotification): int
    {
        $template = $queueNotification->resolveTemplate(QueueTicketDeliveredNotification::TEMPLATE_KEY, 'sms');
        $body = $template?->body;

        if (! is_string($body) || $body === '') {
            return 0;
        }

        return SmsSegmentCalculator::segmentCount(SmsSegmentCalculator::renderForEstimate($body));
    }

    private static function requestId(Request $request): string
    {
        return substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);
    }
}
