<?php

namespace App\Domain\Notification\Support;

/**
 * The two kinds of `callerID` REVE accepts, and what each one may look
 * like.
 *
 * **`callerID` is mandatory in both modes** — confirmed against a live
 * deployment, where omitting it or sending it empty answers
 * `114 Inappropriate request parameter` and nothing is submitted. What
 * differs between the modes is not whether the field is sent but what
 * belongs in it:
 *
 * - **Non-masking** (the default): a *numeric* sender — the operator's
 *   shortcode or the account's assigned number. This is what the vendor's
 *   own examples use (`8809612`, `8801847`). Recipients see a number.
 * - **Masking**: an alphanumeric brand name the operator has approved for
 *   the account. Recipients see the name.
 *
 * Getting the mode and the value out of step does not fail at submission —
 * the gateway accepts either string — it fails later, at the carrier,
 * where it is far harder to diagnose. So the shape is checked at save
 * time, which is the last point where somebody is looking at the field.
 */
class SmsSenderId
{
    /**
     * GSM 03.38 caps an alphanumeric sender ID at 11 characters. Longer is
     * silently truncated or dropped by the carrier rather than refused by
     * the gateway.
     */
    public const int MASKING_MAX_LENGTH = 11;

    public static function maskingEnabled(): bool
    {
        return (bool) app(SmsGatewayConfig::class)->maskingEnabled();
    }

    /**
     * Returns a validation message, or null when the value suits the mode.
     */
    public static function problemWith(string $senderId, bool $masking): ?string
    {
        $senderId = trim($senderId);

        if ($senderId === '') {
            return null; // Clearing the field is handled by the caller.
        }

        if ($masking) {
            if (preg_match('/^[A-Za-z0-9 ._-]+$/', $senderId) !== 1) {
                return 'A masking sender ID may only contain letters, digits, spaces, dots, hyphens and underscores.';
            }

            if (mb_strlen($senderId) > self::MASKING_MAX_LENGTH) {
                return 'A masking sender ID is limited to '.self::MASKING_MAX_LENGTH.' characters — a longer one is dropped by the carrier, not refused by the gateway.';
            }

            return null;
        }

        if (preg_match('/^\d+$/', $senderId) !== 1) {
            return 'Non-masking SMS sends from a number, so the sender ID must be digits only (for example 8809612). Turn on "Use masking" to send from a brand name instead.';
        }

        return null;
    }
}
