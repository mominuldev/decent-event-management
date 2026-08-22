<?php

namespace App\Domain\Shared\Support;

/**
 * Renders the digits of an already-formatted string in Bengali numerals.
 *
 * Carbon's `bn` locale translates month names and meridiems but leaves
 * digits Latin, so "২১ ফেব্রুয়ারি ২০২৭" needs this last step — a Bangla
 * sentence with Latin numerals reads as a half-finished translation.
 *
 * Deliberately opt-in per call rather than applied to everything. An
 * identifier is not a number: a ticket number is quoted down a phone,
 * typed into the admin console and matched against a printed page, and
 * `DEC100-CEN-২০০৫-০০০০১` is unusable for all three. Convert dates,
 * counts and durations; leave references, codes and money alone.
 */
class BanglaNumerals
{
    private const array DIGITS = ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'];

    /**
     * A no-op in every locale but Bangla, so callers can pass a value
     * through unconditionally instead of branching at each site.
     */
    public static function localise(string $value, string $locale): string
    {
        return str_starts_with($locale, 'bn') ? self::convert($value) : $value;
    }

    public static function convert(string $value): string
    {
        return strtr($value, self::DIGITS);
    }
}
