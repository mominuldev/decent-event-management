<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Ticketing\Models\Ticket;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
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
