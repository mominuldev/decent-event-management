<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\Ticket;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VoidTicketRequest;
use App\Http\Resources\TicketResource;
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

        $query = Ticket::query()->with(['ticketType']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('ticket_type_id')) {
            $query->where('ticket_type_id', (int) $request->query('ticket_type_id'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function (Builder $q) use ($search): void {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('holder_name', 'like', "%{$search}%");
            });
        }

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

        $ticket->load(['registration', 'attendee', 'ticketType', 'qrCode', 'checkIns']);

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
                'data' => new TicketResource($ticket->refresh()->load(['ticketType', 'qrCode'])),
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
                'data' => new TicketResource($newTicket->load(['ticketType', 'qrCode'])),
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
}
