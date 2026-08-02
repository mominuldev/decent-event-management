<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Http\Controllers\Api\Scanner\DeviceEnrolmentController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Device enrolment starts here: an Event Manager mints a short-lived,
 * single-use token that the volunteer's phone exchanges for a bound
 * Sanctum token at {@see DeviceEnrolmentController::enrol()}.
 */
class VolunteerController extends Controller
{
    private const int ENROLMENT_TOKEN_TTL_MINUTES = 15;

    public function issueEnrolmentToken(Request $request, VolunteerProfile $volunteer): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('device.enrol'), 403);

        $token = Str::random(40);

        Cache::put(
            "device-enrolment:{$token}",
            ['volunteer_profile_id' => $volunteer->id],
            now()->addMinutes(self::ENROLMENT_TOKEN_TTL_MINUTES)
        );

        return response()->json([
            'enrolment_token' => $token,
            'expires_at' => now()->addMinutes(self::ENROLMENT_TOKEN_TTL_MINUTES),
        ]);
    }
}
