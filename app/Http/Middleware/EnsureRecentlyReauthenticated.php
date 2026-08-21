<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the caller to have re-entered their credentials recently, for
 * the handful of operations docs/06 calls out as needing re-auth — QR
 * signing key rotation being the first (§6.5).
 *
 * The point is not to re-check who the caller is; the bearer token already
 * did that. It is to check that a *person* is present at the keyboard right
 * now, so an unattended session, a borrowed laptop, or a stolen token
 * cannot by itself rotate the key every gate depends on.
 *
 * Confirmation is held in the cache against the specific access token, not
 * against the user: a second session belonging to the same person is a
 * different device and re-confirms on its own. It expires on its own TTL
 * rather than needing a sweeper, which is the whole reason for using the
 * cache rather than a column.
 */
class EnsureRecentlyReauthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! Cache::has(self::cacheKey($token->id))) {
            return response()->json([
                'code' => 'reauthentication_required',
                'message' => 'Confirm your password to continue. This action requires re-authentication.',
                'request_id' => $request->header('X-Request-Id'),
            ], 403);
        }

        return $next($request);
    }

    /**
     * Called by the re-auth endpoint once credentials check out. Takes the
     * request rather than the user because the confirmation belongs to the
     * token that request arrived on, not to the person behind it.
     */
    public static function confirm(Request $request): int
    {
        $token = $request->user()?->currentAccessToken();
        $ttl = (int) config('auth.reauthentication_ttl_minutes', 5);

        if ($token instanceof PersonalAccessToken) {
            Cache::put(self::cacheKey($token->id), true, now()->addMinutes($ttl));
        }

        return $ttl;
    }

    private static function cacheKey(int|string $tokenId): string
    {
        return "reauth:token:{$tokenId}";
    }
}
