<?php

namespace App\Domain\Notification\Support;

use App\Domain\Registration\Support\AttendeeIdentity;

/**
 * Turns a stored mobile number into the MSISDN a Bangladesh SMS gateway
 * will accept: digits only, country code included, no `+`.
 *
 * This is deliberately *not* in {@see AttendeeIdentity}, which normalises
 * for storage and comparison and explicitly refuses to guess that
 * `01711…` means `+8801711…` — that guess is wrong for an overseas
 * alumnus, and getting it wrong there would corrupt the identity a
 * uniqueness constraint is built on. Here the guess is both necessary and
 * safe to make: REVE addresses a handset by MSISDN and nothing else, a
 * leading `0` is a national trunk prefix no international gateway
 * understands, and the worst case is one undelivered message rather than
 * a wrong row.
 *
 * Anything that is already in international form is passed through
 * untouched, so a `+44…` or `+971…` number reaches the gateway as its own
 * country's MSISDN rather than being forced into Bangladesh's.
 */
class Msisdn
{
    private const string BD_COUNTRY_CODE = '880';

    /**
     * @return string|null null when there are not enough digits to be a
     *                     dialable number at all
     */
    public static function format(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $hadPlus = str_starts_with(trim($number), '+');
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if ($digits === '') {
            return null;
        }

        // `00` is the other way of writing `+` — same meaning, and the
        // gateway wants neither.
        if (! $hadPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hadPlus = true;
        }

        // Written internationally already: trust it, whatever country it is.
        if ($hadPlus || str_starts_with($digits, self::BD_COUNTRY_CODE)) {
            return strlen($digits) >= 8 ? $digits : null;
        }

        // Bangladeshi national form: `01XXXXXXXXX` (11 digits) and the
        // occasional `1XXXXXXXXX` written without the trunk prefix.
        if (preg_match('/^01\d{9}$/', $digits) === 1) {
            return self::BD_COUNTRY_CODE.substr($digits, 1);
        }

        if (preg_match('/^1\d{9}$/', $digits) === 1) {
            return self::BD_COUNTRY_CODE.$digits;
        }

        // Something else entirely — a landline, a short code, a typo. Send
        // the digits as given and let the gateway be the judge; refusing
        // here would silently drop a number a human can read fine.
        return strlen($digits) >= 8 ? $digits : null;
    }
}
