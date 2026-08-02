<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TwoFactorAuthenticationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    private const int SESSION_HOURS = 8;

    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor) {}

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

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->string('password'), $user->password)) {
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
