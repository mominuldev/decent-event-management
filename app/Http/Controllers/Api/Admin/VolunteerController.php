<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Http\Controllers\Api\Scanner\DeviceEnrolmentController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;

/**
 * Device enrolment starts here: an Event Manager mints a short-lived,
 * single-use token that the volunteer's phone exchanges for a bound
 * Sanctum token at {@see DeviceEnrolmentController::enrol()}.
 */
#[OAT\Tag(name: 'Volunteers')]
class VolunteerController extends Controller
{
    private const int ENROLMENT_TOKEN_TTL_MINUTES = 15;

    #[OAT\Post(
        path: '/admin/volunteers/{volunteer}/enrolment-token',
        summary: 'Issue a single-use device enrolment token for a volunteer',
        description: 'Step one of device enrolment: an Event Manager mints a short-lived, '
            .'single-use token here. The volunteer\'s phone then exchanges that token for a '
            .'bound Sanctum scanner token at the scanner-guard enrolment endpoint '
            .'(DeviceEnrolmentController::enrol).',
        security: [['bearerAuth' => []]],
        tags: ['Volunteers'],
        parameters: [
            new OAT\Parameter(
                name: 'volunteer',
                description: 'Volunteer profile ULID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Enrolment token issued',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'enrolment_token',
                                type: 'string',
                                description: 'Single-use token, valid for 15 minutes'
                            ),
                            new OAT\Property(
                                property: 'expires_at',
                                type: 'string',
                                format: 'date-time'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing device.enrol permission'),
        ]
    )]
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
