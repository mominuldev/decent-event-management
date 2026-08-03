<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TwoFactorAuthenticationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OAT;

/**
 * Password + mandatory TOTP 2FA for Super Admin / Event Manager — docs/02
 * §2.2. Sessions are capped at 8h via an explicit token expiry, not
 * Sanctum's global default, because the attendee guard needs 30 days.
 */
#[OAT\Tag(name: 'Authentication')]
class AuthController extends Controller
{
    private const int MAX_FAILED_ATTEMPTS = 5;

    private const int LOCKOUT_MINUTES = 15;

    private const int SESSION_HOURS = 8;

    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor) {}

    #[OAT\Post(
        path: '/admin/auth/login',
        summary: 'Admin login with email/password and optional TOTP',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'email',
                            type: 'string',
                            format: 'email',
                            required: ['email']
                        ),
                        new OAT\Property(
                            property: 'password',
                            type: 'string',
                            required: ['password']
                        ),
                        new OAT\Property(
                            property: 'totp_code',
                            type: 'string',
                            description: 'TOTP code if 2FA is enabled'
                        ),
                        new OAT\Property(
                            property: 'device_name',
                            type: 'string',
                            description: 'Device name for the token'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful login',
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
                                property: 'requires_2fa_setup',
                                type: 'boolean'
                            ),
                            new OAT\Property(
                                property: 'user',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'name', type: 'string'),
                                    new OAT\Property(property: 'email', type: 'string', format: 'email'),
                                    new OAT\Property(property: 'roles', type: 'array', items: new OAT\Items(type: 'string')),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Invalid credentials'),
            new OAT\Response(response: 423, description: 'Account locked'),
        ]
    )]
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

        $twoFactorConfirmed = $user->two_factor_confirmed_at !== null;

        // Local-only convenience: never active in testing/staging/production,
        // so the mandatory-2FA invariant (docs/02 §2.2) stays fully enforced
        // everywhere it matters.
        $bypass2fa = app()->environment('local');

        if ($twoFactorConfirmed && ! $bypass2fa) {
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

        $grantFullAccess = $twoFactorConfirmed || $bypass2fa;

        // Staff without confirmed 2FA get a setup-only token — see routes/api/v1.php.
        $ability = $grantFullAccess ? 'admin' : '2fa-setup';
        $expiresAt = $grantFullAccess ? now()->addHours(self::SESSION_HOURS) : now()->addMinutes(30);

        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'admin-console',
            [$ability],
            $expiresAt
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt,
            'requires_2fa_setup' => ! $grantFullAccess,
            'user' => [
                'ulid' => $user->ulid,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    }

    #[OAT\Get(
        path: '/admin/auth/me',
        summary: 'Get the authenticated staff member\'s own profile, roles, and permissions',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Caller profile',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'ulid', type: 'string'),
                            new OAT\Property(property: 'name', type: 'string'),
                            new OAT\Property(property: 'email', type: 'string', format: 'email'),
                            new OAT\Property(property: 'roles', type: 'array', items: new OAT\Items(type: 'string')),
                            new OAT\Property(property: 'permissions', type: 'array', items: new OAT\Items(type: 'string')),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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

    #[OAT\Post(
        path: '/admin/auth/logout',
        summary: 'Revoke the current access token',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Logged out',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [new OAT\Property(property: 'message', type: 'string')]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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
