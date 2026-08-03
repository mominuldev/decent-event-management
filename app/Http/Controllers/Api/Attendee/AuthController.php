<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Notification\Models\Notification;
use App\Domain\Registration\Models\Attendee;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;

/**
 * Magic link (email) or OTP (SMS) — attendees never have a password.
 * See docs/02 §2.2 "Attendee".
 */
#[OAT\Tag(name: 'Authentication')]
class AuthController extends Controller
{
    private const int TOKEN_TTL_MINUTES = 15;

    private const int SESSION_DAYS = 30;

    #[OAT\Post(
        path: '/attendee/auth/request-link',
        summary: 'Request a login link for an attendee, sent by SMS to their registered mobile number',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'mobile',
                            type: 'string',
                            required: ['mobile']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Same response whether or not the number is registered, to avoid leaking enumeration signal. In local/testing environments only, the response also includes `debug_token`.',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'message', type: 'string'),
                            new OAT\Property(
                                property: 'debug_token',
                                type: 'string',
                                description: 'Plaintext login token, only present in local/testing environments'
                            ),
                        ]
                    )
                )
            ),
        ]
    )]
    public function requestLink(Request $request): JsonResponse
    {
        $request->validate(['mobile' => ['required', 'string']]);

        $attendee = Attendee::where('mobile', $request->string('mobile'))->first();

        // Same response whether or not the number is registered — the
        // enumeration signal isn't worth leaking here.
        if (! $attendee) {
            return response()->json(['message' => 'If that number is registered, a login link has been sent.']);
        }

        $plainToken = Str::random(48);

        $attendee->forceFill([
            'auth_token_hash' => hash('sha256', $plainToken),
            'auth_token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
        ])->save();

        Notification::create([
            'notifiable_type' => 'attendee',
            'notifiable_id' => $attendee->id,
            'attendee_id' => $attendee->id,
            'template_key' => 'attendee.login_link',
            'channel' => 'sms',
            'recipient' => $attendee->mobile,
            'payload' => ['token' => $plainToken],
            'status' => 'queued',
            'priority' => 1,
            'max_attempts' => 5,
        ]);

        $response = ['message' => 'If that number is registered, a login link has been sent.'];

        if (app()->environment('local', 'testing')) {
            $response['debug_token'] = $plainToken;
        }

        return response()->json($response);
    }

    #[OAT\Post(
        path: '/attendee/auth/verify',
        summary: 'Verify a login link token and obtain an attendee bearer token',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'token',
                            type: 'string',
                            description: 'Plaintext login token received via SMS',
                            required: ['token']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Token verified, session created',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'token',
                                type: 'string',
                                description: 'API bearer token'
                            ),
                            new OAT\Property(
                                property: 'expires_at',
                                type: 'string',
                                format: 'date-time'
                            ),
                            new OAT\Property(
                                property: 'attendee',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'full_name', type: 'string'),
                                    new OAT\Property(property: 'mobile', type: 'string'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Invalid or expired login link'),
        ]
    )]
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $attendee = Attendee::where('auth_token_hash', hash('sha256', $request->string('token')))
            ->where('auth_token_expires_at', '>', now())
            ->first();

        if (! $attendee) {
            return response()->json(['message' => 'Invalid or expired login link.'], 401);
        }

        $attendee->forceFill([
            'auth_token_hash' => null,
            'auth_token_expires_at' => null,
        ])->save();

        $token = $attendee->createToken('attendee-session', ['attendee'], now()->addDays(self::SESSION_DAYS));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => now()->addDays(self::SESSION_DAYS),
            'attendee' => [
                'ulid' => $attendee->ulid,
                'full_name' => $attendee->full_name,
                'mobile' => $attendee->mobile,
            ],
        ]);
    }

    #[OAT\Post(
        path: '/attendee/auth/logout',
        summary: 'Revoke the current attendee bearer token',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Logged out',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();
        $attendee->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
