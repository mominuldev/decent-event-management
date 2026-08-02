<?php

namespace App\Http\Controllers\Api\Scanner;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Shared\Models\EventSetting;
use App\Http\Controllers\Api\Admin\VolunteerController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Exchanges a one-time enrolment token (minted by an Event Manager via
 * {@see VolunteerController::issueEnrolmentToken()})
 * for a device-bound Sanctum token. Binds the token to hardware so a
 * leaked token cannot be used from an arbitrary machine — docs/03 §3.20.
 */
class DeviceEnrolmentController extends Controller
{
    public function enrol(Request $request): JsonResponse
    {
        $request->validate([
            'enrolment_token' => ['required', 'string'],
            'device_fingerprint' => ['required', 'string', 'max:190'],
            'device_name' => ['required', 'string', 'max:100'],
            'device_code' => ['required', 'string', 'max:16'],
            'platform' => ['required', 'in:android,ios'],
            'pin' => ['required', 'digits:6'],
        ]);

        $cacheKey = "device-enrolment:{$request->string('enrolment_token')}";
        $payload = Cache::get($cacheKey);

        if (! $payload) {
            throw ValidationException::withMessages(['enrolment_token' => ['Invalid or expired enrolment token.']]);
        }

        /** @var VolunteerProfile $volunteer */
        $volunteer = VolunteerProfile::with('user')->findOrFail($payload['volunteer_profile_id']);

        if ($volunteer->isRevoked() || ! $volunteer->is_active) {
            throw ValidationException::withMessages(['enrolment_token' => ['Volunteer access has been revoked.']]);
        }

        $user = $volunteer->user;

        if ($user === null) {
            throw ValidationException::withMessages(['enrolment_token' => ['Volunteer has no linked staff account.']]);
        }

        if ($volunteer->pin_hash === null) {
            $volunteer->forceFill(['pin_hash' => Hash::make($request->string('pin')), 'pin_set_at' => now()])->save();
        } elseif (! Hash::check($request->string('pin'), $volunteer->pin_hash)) {
            throw ValidationException::withMessages(['pin' => ['Incorrect PIN.']]);
        }

        Cache::forget($cacheKey);

        $device = CheckInDevice::updateOrCreate(
            ['device_fingerprint' => $request->string('device_fingerprint')],
            [
                'device_code' => $request->string('device_code'),
                'device_name' => $request->string('device_name'),
                'platform' => $request->string('platform'),
                'assigned_volunteer_profile_id' => $volunteer->id,
                'status' => 'active',
                'enrolled_by_user_id' => $volunteer->user_id,
                'enrolled_at' => now(),
            ]
        );

        $expiresAt = $this->tokenExpiryFromCheckInWindow();

        $user->tokens()->where('name', "device:{$device->device_code}")->delete();
        $token = $user->createToken("device:{$device->device_code}", ['scanner'], $expiresAt);

        $device->forceFill(['sanctum_token_id' => $token->accessToken->id])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt,
            'device' => ['ulid' => $device->ulid, 'code' => $device->device_code],
        ]);
    }

    private function tokenExpiryFromCheckInWindow(): Carbon
    {
        $windowEnd = EventSetting::where('key', 'checkin.window_end')->value('value');

        return $windowEnd ? Carbon::parse($windowEnd) : now()->addHours(12);
    }
}
