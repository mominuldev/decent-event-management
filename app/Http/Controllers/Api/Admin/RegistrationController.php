<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\ActivityLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RegistrationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Registration::query()->with(['attendee', 'guests', 'ticketType', 'payments']);

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function (Builder $q) use ($search): void {
                $q->where('registration_number', 'like', "%{$search}%")
                    ->orWhereHas('attendee', function (Builder $q2) use ($search): void {
                        $q2->where('full_name', 'like', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('ticket_type_id')) {
            $query->where('ticket_type_id', (string) $request->input('ticket_type_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->input('date_to'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return RegistrationResource::collection($query->paginate($perPage));
    }

    public function show(Registration $registration): RegistrationResource
    {
        $registration->load([
            'attendee',
            'guests',
            'ticketType',
            'payments',
            'tickets',
        ]);

        return new RegistrationResource($registration);
    }

    public function update(UpdateRegistrationRequest $request, Registration $registration): RegistrationResource
    {
        $oldData = $registration->toArray();
        $validated = $request->validated();

        if (isset($validated['status']) && $validated['status'] !== $registration->status) {
            $registration->transitionTo((string) $validated['status']);
        }

        unset($validated['status']);

        if (! empty($validated)) {
            $registration->update($validated);
        }

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'registration',
            'event' => 'updated',
            'description' => 'Registration updated',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $registration->getMorphClass(),
            'subject_id' => $registration->id,
            'properties' => ['old' => $oldData, 'new' => $registration->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new RegistrationResource($registration->refresh());
    }

    public function destroy(Request $request, Registration $registration): JsonResponse|Response
    {
        if (in_array($registration->status, ['paid', 'confirmed'], true)) {
            return response()->json([
                'code' => 'deletion_prevented',
                'message' => 'Cannot delete paid or confirmed registrations.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $registration->delete();

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'registration',
            'event' => 'deleted',
            'description' => 'Registration soft deleted',
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $registration->getMorphClass(),
            'subject_id' => $registration->id,
            'properties' => ['registration_ulid' => $registration->ulid],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return response()->noContent();
    }
}
