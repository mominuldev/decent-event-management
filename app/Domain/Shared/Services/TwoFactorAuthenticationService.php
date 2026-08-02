<?php

namespace App\Domain\Shared\Services;

use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * TOTP 2FA for Super Admin (mandatory) and Event Manager — docs/02 §2.2.
 */
class TwoFactorAuthenticationService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function qrCodeSvg(string $holderEmail, string $secret): string
    {
        return $this->engine->getQRCodeInline(
            config('app.name'),
            $holderEmail,
            $secret
        );
    }

    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, $code);
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(bin2hex(random_bytes(5))))
            ->values()
            ->all();
    }
}
