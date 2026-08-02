<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Domain\Notification\Models\Notification;
use App\Domain\Registration\Models\Attendee;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Magic link (email) or OTP (SMS) — attendees never have a password.
 * See docs/02 §2.2 "Attendee".
 */
class AuthController extends Controller
{
    private const int TOKEN_TTL_MINUTES = 15;

    private const int SESSION_DAYS = 30;

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

    public function logout(Request $request): JsonResponse
    {
        /** @var Attendee $attendee */
        $attendee = $request->user();
        $attendee->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
