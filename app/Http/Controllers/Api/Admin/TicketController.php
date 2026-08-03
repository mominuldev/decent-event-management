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

class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
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

    public function show(Ticket $ticket): TicketResource
    {
        $ticket->load(['registration', 'attendee', 'ticketType', 'qrCode', 'checkIns']);

        return new TicketResource($ticket);
    }

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

    public function reissue(Request $request, Ticket $ticket, IssueTicket $action): JsonResponse
    {
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
