<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Actions\ChangeStaffPassword;
use App\Domain\Shared\Actions\UpdateStaffProfile;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TwoFactorAuthenticationService;
use App\Domain\Shared\Support\PasswordHash;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureRecentlyReauthenticated;
use App\Http\Requests\Admin\ChangePasswordRequest;
use App\Http\Requests\Admin\ForgotPasswordRequest;
use App\Http\Requests\Admin\LoginRequest;
use App\Http\Requests\Admin\ResetPasswordRequest;
use App\Http\Requests\Admin\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OAT;
use Throwable;

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

        if (! PasswordHash::isUsable($user->password)) {
            // Not a failed attempt: nobody guessed anything, and counting it
            // would lock the account after five tries and replace this with a
            // 423, moving the message even further from the cause. The log
            // line is the only place the real reason surfaces, since the
            // caller is told exactly what a wrong password is told.
            Log::warning('Staff account has a password hash the configured hasher cannot read; login is impossible until it is reset.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'hasher' => config('hashing.driver'),
                'fix' => 'php artisan admin:create-super-admin --email='.$user->email.' --force',
            ]);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (! PasswordHash::matches((string) $request->string('password'), $user->password)) {
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

        return response()->json($this->accountPayload($user));
    }

    #[OAT\Post(
        path: '/admin/auth/forgot-password',
        summary: 'Email yourself a password reset link',
        description: 'Always answers 200 with the same body, whether or not the address belongs to an account.',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['email'],
                    properties: [new OAT\Property(property: 'email', type: 'string', format: 'email')]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Accepted — a link is sent only if the address belongs to an active account'),
            new OAT\Response(response: 429, description: 'Too many requests for this address or from this IP'),
        ]
    )]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->string('email')->value();

        $user = User::where('email', $email)->first();

        // Suspended and soft-deleted accounts are silently skipped rather than
        // refused: a different answer for them would say which addresses
        // belong to somebody who used to work here.
        if ($user !== null && $user->isActive()) {
            try {
                Password::broker()->sendResetLink(['email' => $email]);
            } catch (Throwable $e) {
                // A transport failure must not change the answer. Letting it
                // escape would 500 for an address that has an account and 200
                // for one that does not — turning a misconfigured mailer into
                // a way to enumerate staff, on the one endpoint most carefully
                // written not to be. The operator finds out here instead.
                Log::error('Could not send a staff password reset email.', [
                    'email' => $email,
                    'mailer' => config('mail.default'),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // One body, always. Whether an address has an account is not something
        // an unauthenticated caller gets to find out — the whole point of the
        // rest of this flow being careful is lost if this line branches.
        return response()->json([
            'message' => 'If that address belongs to a staff account, a reset link is on its way.',
        ]);
    }

    #[OAT\Post(
        path: '/admin/auth/reset-password',
        summary: 'Set a new password using an emailed reset token',
        description: 'Revokes every existing session. Does not sign you in — 2FA still applies at the next login.',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['token', 'email', 'password', 'password_confirmation'],
                    properties: [
                        new OAT\Property(property: 'token', type: 'string'),
                        new OAT\Property(property: 'email', type: 'string', format: 'email'),
                        new OAT\Property(property: 'password', type: 'string', minLength: ChangePasswordRequest::MIN_LENGTH),
                        new OAT\Property(property: 'password_confirmation', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Password set; sign in with it'),
            new OAT\Response(response: 422, description: 'The link is invalid or expired, or the password fails the policy'),
        ]
    )]
    public function resetPassword(ResetPasswordRequest $request, ChangeStaffPassword $action): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($action, $request): void {
                $action->afterReset(
                    $user,
                    $password,
                    $request->ip(),
                    $request->header('X-Request-Id'),
                );
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One message for an expired token, a wrong token and an address
            // with no account alike: distinguishing them would confirm who
            // holds an account to anyone who can guess a token format.
            throw ValidationException::withMessages([
                'token' => ['This reset link is invalid or has expired. Request a new one.'],
            ]);
        }

        // Deliberately no token issued. A reset proves someone can read the
        // mailbox, which is not the second factor — signing in still requires
        // the authenticator code where 2FA is confirmed.
        return response()->json([
            'message' => 'Password set. Sign in with your new password.',
        ]);
    }

    #[OAT\Patch(
        path: '/admin/auth/me',
        summary: 'Update your own name, email address and phone number',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['name', 'email'],
                    properties: [
                        new OAT\Property(property: 'name', type: 'string', maxLength: 150),
                        new OAT\Property(property: 'email', type: 'string', format: 'email', maxLength: 190),
                        new OAT\Property(property: 'phone', type: 'string', maxLength: 20, nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'The updated account'),
            new OAT\Response(response: 422, description: 'Validation failed — the email may belong to another staff account'),
        ]
    )]
    public function updateProfile(UpdateProfileRequest $request, UpdateStaffProfile $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute(
            $user,
            $request->validated(),
            $request->ip(),
            $request->header('X-Request-Id'),
        );

        return response()->json($this->accountPayload($user->refresh()));
    }

    #[OAT\Post(
        path: '/admin/auth/password',
        summary: 'Change your own password',
        description: 'Requires the current password. Every other session is revoked on success.',
        tags: ['Authentication'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['current_password', 'password', 'password_confirmation'],
                    properties: [
                        new OAT\Property(property: 'current_password', type: 'string'),
                        new OAT\Property(property: 'password', type: 'string', minLength: ChangePasswordRequest::MIN_LENGTH),
                        new OAT\Property(property: 'password_confirmation', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Password changed; other sessions revoked'),
            new OAT\Response(response: 422, description: 'The current password is wrong, or the new one fails the policy'),
        ]
    )]
    public function changePassword(ChangePasswordRequest $request, ChangeStaffPassword $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // PasswordHash rather than Hash::check or the `current_password` rule:
        // both of those throw instead of failing when the stored value is not
        // readable by the configured hasher, which would turn a wrong answer
        // here into a 500.
        if (! PasswordHash::matches($request->string('current_password')->value(), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['That is not your current password.'],
            ]);
        }

        // The session doing the changing, which survives while every other one
        // is revoked. Read the same way logout() reads it: these routes are
        // behind the Sanctum guard, so a bearer token model is always what is
        // there.
        $revoked = $action->execute(
            $user,
            $request->string('password')->value(),
            $user->currentAccessToken()->getKey(),
            $request->ip(),
            $request->header('X-Request-Id'),
        );

        return response()->json([
            'message' => 'Password changed.',
            'other_sessions_revoked' => $revoked,
        ]);
    }

    /**
     * The one definition of what this app tells you about your own account,
     * so `me` and the response to editing it cannot drift apart.
     *
     * @return array<string, mixed>
     */
    private function accountPayload(User $user): array
    {
        return [
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
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

    #[OAT\Post(
        path: '/admin/auth/reauth',
        summary: 'Re-confirm credentials for an action that requires re-authentication',
        description: 'Proves a person is present at the keyboard before a high-consequence action such as QR signing key rotation (docs/06 §6.5). The confirmation is bound to the access token used, lasts a few minutes, and does not issue a new token.',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['password'],
                    properties: [
                        new OAT\Property(property: 'password', type: 'string', format: 'password'),
                        new OAT\Property(property: 'totp_code', type: 'string', description: 'Required when the account has 2FA confirmed'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Re-authenticated',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'confirmed', type: 'boolean'),
                            new OAT\Property(property: 'expires_in_minutes', type: 'integer'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 422, description: 'Password or TOTP code incorrect'),
        ]
    )]
    public function reauth(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'password' => ['required', 'string'],
            'totp_code' => ['sometimes', 'string'],
        ]);

        // Deliberately does NOT run registerFailedAttempt(): this caller is
        // already authenticated, and letting a mistyped confirmation lock
        // the account would turn a fumbled re-auth into a lockout in the
        // middle of the very incident the operator is responding to.
        if (! PasswordHash::matches((string) $request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        // Where 2FA is confirmed, re-auth means both factors again — a
        // password alone is exactly what a shoulder-surfer or a reused
        // credential gets you, and this gate exists for the case where the
        // token itself may already be in the wrong hands.
        if ($user->two_factor_confirmed_at !== null) {
            $code = $request->string('totp_code')->value();

            if ($code === '' || $user->two_factor_secret === null || ! $this->twoFactor->verify($user->two_factor_secret, $code)) {
                throw ValidationException::withMessages([
                    'totp_code' => ['The two-factor code is incorrect or missing.'],
                ]);
            }
        }

        $ttl = EnsureRecentlyReauthenticated::confirm($request);

        return response()->json([
            'confirmed' => true,
            'expires_in_minutes' => $ttl,
        ]);
    }

    private function registerFailedAttempt(User $user): void
    {
        // A lockout that has elapsed has been served, so the count that caused
        // it starts again. Without this the counter stays at MAX_FAILED_ATTEMPTS
        // for ever and the first mistype after every cooldown re-locks
        // immediately -- one attempt per LOCKOUT_MINUTES, indefinitely, which
        // is not "five tries then a pause" but a permanent one-try account.
        $previous = $user->locked_until !== null && $user->locked_until->isPast()
            ? 0
            : $user->failed_login_attempts;

        $attempts = $previous + 1;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= self::MAX_FAILED_ATTEMPTS
                ? now()->addMinutes(self::LOCKOUT_MINUTES)
                : null,
        ])->save();
    }
}
