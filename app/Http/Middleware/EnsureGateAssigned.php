<?php

namespace App\Http\Middleware;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\VolunteerGateAssignment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A scan naming a `gate_id` must be scanning at a gate the volunteer is
 * actually assigned to — docs/02 §2.2. Requests with no gate context
 * (e.g. the manifest pull) pass through untouched.
 */
class EnsureGateAssigned
{
    public function handle(Request $request, Closure $next): Response
    {
        $gateId = $request->input('gate_id');

        if ($gateId === null) {
            return $next($request);
        }

        /** @var CheckInDevice|null $device */
        $device = $request->attributes->get('checkin_device');

        $assigned = $device?->volunteerProfile
            && VolunteerGateAssignment::where('volunteer_profile_id', $device->volunteerProfile->id)
                ->where('gate_id', $gateId)
                ->exists();

        if (! $assigned) {
            return response()->json(['message' => 'Volunteer is not assigned to this gate.'], 403);
        }

        return $next($request);
    }
}
