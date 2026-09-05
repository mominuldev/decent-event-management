<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TwoFactorAuthenticationService;
use App\Domain\Shared\Support\PasswordHash;
use App\Domain\Shared\Support\TwoFactorPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OAT;

/**
 * Enrolling in, and dropping, TOTP 2FA on your own account.
 *
 * These endpoints work whether or not `security.two_factor_enabled` is on
 * (see {@see TwoFactorPolicy}), so an account can enrol ahead of the switch
 * being flipped. While the switch is off an enrolled account simply is not
 * asked for its code at login, and its enrolment is left untouched.
 *
 * The admin SPA has no voluntary-enrolment screen yet — it reaches the setup
 * page only when a login tells it enrolment is required — so today that
 * ordering is available over the API only.
 */
#[OAT\Tag(name: 'Two-Factor')]
class TwoFactorController extends Controller
{
    private const int SESSION_HOURS = 8;

    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor) {}

    #[OAT\Post(
        path: '/admin/auth/2fa/setup',
        summary: 'Generate a new TOTP secret and QR code for the authenticated staff member',
        description: 'Self-service: acts on the caller\'s own account only, no RBAC permission '.
            'check beyond authentication. Available to a token holding either the `admin` or '.
            'the setup-only `2fa-setup` ability.',
        security: [['bearerAuth' => []]],
        tags: ['Two-Factor'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'TOTP secret generated',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'secret', type: 'string', description: 'TOTP secret key'),
                            new OAT\Property(property: 'qr_code_svg', type: 'string', description: 'QR code as inline SVG markup'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 409, description: '2FA is already enabled — disable it first to reconfigure'),
        ]
    )]
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_confirmed_at !== null) {
            return response()->json(['message' => '2FA is already enabled. Disable it first to reconfigure.'], 409);
        }

        $secret = $this->twoFactor->generateSecretKey();

        $user->forceFill(['two_factor_secret' => $secret])->save();

        return response()->json([
            'secret' => $secret,
            'qr_code_svg' => $this->twoFactor->qrCodeSvg($user->email, $secret),
        ]);
    }

    #[OAT\Post(
        path: '/admin/auth/2fa/confirm',
        summary: 'Confirm 2FA setup with a TOTP code and activate it',
        description: 'Self-service: acts on the caller\'s own account only, no RBAC permission '.
            'check beyond authentication. Requires a prior call to /admin/auth/2fa/setup. On '.
            'success, revokes the setup-only token and issues a full `admin`-ability token.',
        security: [['bearerAuth' => []]],
        tags: ['Two-Factor'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'code',
                            type: 'string',
                            description: '6-digit TOTP code',
                            required: ['code']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: '2FA enabled; a new full-access token is issued',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'message', type: 'string'),
                            new OAT\Property(
                                property: 'recovery_codes',
                                type: 'array',
                                items: new OAT\Items(type: 'string')
                            ),
                            new OAT\Property(property: 'token', type: 'string', description: 'New full-access API bearer token'),
                            new OAT\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 422, description: 'Missing/invalid code, or setup was never called'),
        ]
    )]
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_secret === null) {
            throw ValidationException::withMessages(['code' => ['Call /2fa/setup first.']]);
        }

        if (! $this->twoFactor->verify($user->two_factor_secret, $request->string('code')->value())) {
            throw ValidationException::withMessages(['code' => ['Invalid authentication code.']]);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => json_encode($recoveryCodes),
        ])->save();

        // The setup-only token is no longer valid — issue a full admin token.
        $user->currentAccessToken()->delete();
        $token = $user->createToken('admin-console', ['admin'], now()->addHours(self::SESSION_HOURS));

        return response()->json([
            'message' => '2FA enabled.',
            'recovery_codes' => $recoveryCodes,
            'token' => $token->plainTextToken,
            'expires_at' => now()->addHours(self::SESSION_HOURS),
        ]);
    }

    #[OAT\Post(
        path: '/admin/auth/2fa/disable',
        summary: 'Disable 2FA for the authenticated staff member',
        description: 'Self-service: acts on the caller\'s own account only, no RBAC permission '.
            'check beyond authentication. Requires the caller to re-enter their password. '.
            'Only reachable with a full `admin`-ability token.',
        security: [['bearerAuth' => []]],
        tags: ['Two-Factor'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'password',
                            type: 'string',
                            format: 'password',
                            required: ['password']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: '2FA disabled',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 422, description: 'Missing or incorrect password'),
        ]
    )]
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        // PasswordHash, not Hash::check: the latter throws rather than
        // returning false when the stored value is not a hash the configured
        // hasher can read, turning a wrong answer here into a 500. This call
        // site was missed when the rest of the codebase moved across.
        if (! PasswordHash::matches($request->string('password')->value(), $user->password)) {
            throw ValidationException::withMessages(['password' => ['Incorrect password.']]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => '2FA disabled.']);
    }
}
