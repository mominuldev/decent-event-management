<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Ticketing\Models\Ticket;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Attendee Self-Service')]
class TicketController extends Controller
{
    #[OAT\Get(
        path: '/attendee/tickets/{ticket}',
        summary: 'Get a single ticket owned by the authenticated attendee',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        parameters: [
            new OAT\Parameter(
                name: 'ticket',
                description: 'Ticket ULID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The ticket',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'ulid', type: 'string'),
                            new OAT\Property(property: 'ticket_number', type: 'string'),
                            new OAT\Property(property: 'status', type: 'string'),
                            new OAT\Property(property: 'admits_total', type: 'integer'),
                            new OAT\Property(property: 'admitted_count', type: 'integer'),
                            new OAT\Property(property: 'price_paid_paisa', type: 'integer'),
                            new OAT\Property(property: 'currency', type: 'string'),
                            new OAT\Property(property: 'holder_name', type: 'string', nullable: true),
                            new OAT\Property(property: 'holder_batch_year', nullable: true),
                            new OAT\Property(property: 'holder_type_label', type: 'string', nullable: true),
                            new OAT\Property(property: 'issued_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'voided_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'void_reason', type: 'string', nullable: true),
                            new OAT\Property(property: 'first_admitted_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'last_admitted_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'manifest_version', nullable: true),
                            new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                        ]
                    )
                )
            ),
            new OAT\Response(
                response: 404,
                description: 'Ticket not found, or not owned by the authenticated attendee',
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
    public function show(Request $request, Ticket $ticket): TicketResource|JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        if ($ticket->attendee_id !== $attendee->id) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'Ticket not found.',
            ], 404);
        }

        $ticket->load(['ticketType', 'qrCode', 'registration']);

        return new TicketResource($ticket);
    }

    #[OAT\Get(
        path: '/attendee/tickets/{ticket}/pdf',
        summary: 'Get the short-TTL signed URL for an owned ticket\'s generated PDF',
        description: 'Returns a JSON object with the stored media `path` for the ticket PDF, not the binary itself — the PDF is served separately via a short-TTL signed URL, per docs/06 file-serving conventions.',
        security: [['bearerAuth' => []]],
        tags: ['Attendee Self-Service'],
        parameters: [
            new OAT\Parameter(
                name: 'ticket',
                description: 'Ticket ULID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'PDF media reference',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'path', type: 'string', nullable: true),
                        ]
                    )
                )
            ),
            new OAT\Response(
                response: 404,
                description: 'Ticket not found / not owned by the authenticated attendee, or the PDF has not been generated yet',
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
    public function downloadPdf(Request $request, Ticket $ticket): JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        if ($ticket->attendee_id !== $attendee->id) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'Ticket not found.',
            ], 404);
        }

        if ($ticket->pdf_media_id === null) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'PDF not generated yet.',
            ], 404);
        }

        $ticket->load('pdf');

        return response()->json(['path' => $ticket->pdf?->path]);
    }
}
