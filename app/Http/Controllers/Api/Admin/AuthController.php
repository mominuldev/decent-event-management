<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TwoFactorAuthenticationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Password + mandatory TOTP 2FA for Super Admin / Event Manager — docs/02
 * §2.2. Sessions are capped at 8h via an explicit token expiry, not
 * Sanctum's global default, because the attendee guard needs 30 days.
 */
class AuthController extends Controller
{
    private const int MAX_FAILED_ATTEMPTS = 5;

    private const int LOCKOUT_MINUTES = 15;

    private const int SESSION_HOURS = 8;

    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->locked_until?->isFuture()) {
            return response()->json([
                'message' => 'Account temporarily locked. Try again later.',
                'locked_until' => $user->locked_until,
            ], 423);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'Account is not active.'], 403);
        }

        if (! Hash::check($request->string('password'), $user->password)) {
            $this->registerFailedAttempt($user);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $requires2fa = $user->two_factor_confirmed_at !== null;

        if ($requires2fa) {
            $code = $request->string('totp_code')->value();

            if ($code === '' || $user->two_factor_secret === null || ! $this->twoFactor->verify($user->two_factor_secret, $code)) {
                return response()->json(['message' => 'Invalid or missing authentication code.'], 401);
            }
        }

        $ip = $request->ip();

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip !== null ? inet_pton($ip) : null,
        ])->save();

        // Staff without confirmed 2FA get a setup-only token — see routes/api/v1.php.
        $ability = $requires2fa ? 'admin' : '2fa-setup';
        $expiresAt = $requires2fa ? now()->addHours(self::SESSION_HOURS) : now()->addMinutes(30);

        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'admin-console',
            [$ability],
            $expiresAt
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt,
            'requires_2fa_setup' => ! $requires2fa,
            'user' => [
                'ulid' => $user->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    private function registerFailedAttempt(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= self::MAX_FAILED_ATTEMPTS
                ? now()->addMinutes(self::LOCKOUT_MINUTES)
                : null,
        ])->save();
    }
}
