<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\UpdateRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        $registrations = Registration::with(['guests', 'ticketType', 'eventSession'])
            ->where('attendee_id', $attendee->id)
            ->get();

        return RegistrationResource::collection($registrations);
    }

    public function update(UpdateRegistrationRequest $request, Registration $registration): RegistrationResource
    {
        $registration->update($request->only(['comments', 'special_notes']));

        if ($request->has('guests')) {
            DB::transaction(function () use ($registration, $request): void {
                $registration->guests()->delete();

                /** @var array<int, array<string, mixed>> $guests */
                $guests = $request->input('guests', []);

                if (count($guests) > 0) {
                    $registration->guests()->createMany($guests);
                }
            });
        }

        $registration->load(['guests', 'ticketType', 'eventSession']);

        return new RegistrationResource($registration);
    }

    public function cancel(Request $request, Registration $registration): RegistrationResource|JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();

        if ($registration->attendee_id !== $attendee->id) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'Registration not found.',
            ], 404);
        }

        if ($registration->status !== 'pending_payment') {
            return response()->json([
                'code' => 'INVALID_STATUS',
                'message' => 'Only pending_payment registrations can be cancelled.',
            ], 400);
        }

        DB::transaction(function () use ($registration): void {
            $registration->transitionTo('cancelled');

            $registration->payments()->where('status', 'pending')->update(['status' => 'cancelled']);

            DB::update(
                'UPDATE ticket_types SET quantity_reserved = GREATEST(0, quantity_reserved - 1) WHERE id = ?',
                [$registration->ticket_type_id]
            );
        });

        return new RegistrationResource($registration);
    }
}
