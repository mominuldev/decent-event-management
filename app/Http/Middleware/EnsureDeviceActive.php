<?php

namespace App\Http\Middleware;

use App\Domain\CheckIn\Models\CheckInDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A volunteer token is only useful bound to the enrolled device that
 * requested it. Revocation must be immediate — see docs/02 §2.2.
 */
class EnsureDeviceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        $device = $token
            ? CheckInDevice::where('sanctum_token_id', $token->id)->first()
            : null;

        if (! $device || ! $device->isActive()) {
            return response()->json(['message' => 'Device is not enrolled or has been revoked.'], 403);
        }

        $device->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('checkin_device', $device);

        return $next($request);
    }
}
