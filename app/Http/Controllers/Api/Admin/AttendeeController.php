<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\ActivityLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAttendeeRequest;
use App\Http\Resources\AttendeeResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttendeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Attendee::query()->with(['profilePhoto']);

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $q) use ($search): void {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('participant_type')) {
            $query->where('participant_type', (string) $request->input('participant_type'));
        }

        if ($request->filled('ssc_batch_year')) {
            $query->where('ssc_batch_year', (int) $request->input('ssc_batch_year'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return AttendeeResource::collection($query->paginate($perPage));
    }

    public function show(Attendee $attendee): AttendeeResource
    {
        $attendee->load([
            'profilePhoto',
            'registrations.ticketType',
            'registrations.payments',
            'registrations.tickets',
        ]);

        return new AttendeeResource($attendee);
    }

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

    public function destroy(Request $request, Attendee $attendee): JsonResponse|Response
    {
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
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $attendee->getMorphClass(),
            'subject_id' => $attendee->id,
            'properties' => ['attendee_ulid' => $attendee->ulid],
            'ip_address' => $request->ip(),
            'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
        ]);

        return response()->noContent();
    }
}
