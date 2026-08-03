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
use Symfony\Component\HttpFoundation\Response;

class TicketTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $ticketTypes = TicketType::query()->orderBy('sort_order')->get();

        return TicketTypeResource::collection($ticketTypes);
    }

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

    public function show(TicketType $ticketType): TicketTypeResource
    {
        return new TicketTypeResource($ticketType);
    }

    public function update(UpdateTicketTypeRequest $request, TicketType $ticketType): TicketTypeResource|JsonResponse
    {
        $validated = $request->validated();

        if ($ticketType->quantity_sold > 0) {
            $restrictedKeys = ['code', 'base_price_paisa', 'additional_adult_price_paisa', 'additional_child_price_paisa'];
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

    public function destroy(Request $request, TicketType $ticketType): JsonResponse|Response
    {
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
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $ticketType->getMorphClass(),
            'subject_id' => $ticketType->id,
            'properties' => ['ticket_type_ulid' => $ticketType->ulid],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return response()->noContent();
    }
}
