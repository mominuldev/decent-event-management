<?php

namespace App\Domain\Notification\Support;

use App\Domain\Notification\Channels\FakeSmsDriver;

/**
 * Bangladesh-specific SMS cost accounting (docs/01 §1.6): a GSM-7 (Latin,
 * ASCII-punctuation) message holds 160 characters per segment; a Unicode
 * message — any Bangla text, or a single non-GSM-7 character mixed into
 * an otherwise-Latin message — drops to 70 per segment, because the
 * carrier encodes the whole message as UCS-2 the moment one character
 * needs it. {@see FakeSmsDriver} uses
 * this instead of its own hardcoded 160-chars-per-segment math so tests
 * exercise the real budgeting rule.
 */
class SmsSegmentCalculator
{
    private const int GSM7_CHARS_PER_SEGMENT = 160;

    private const int UNICODE_CHARS_PER_SEGMENT = 70;

    /**
     * GSM 03.38 basic character set (default alphabet), the characters an
     * SMS can carry at 160-per-segment cost. Extension-table characters
     * (e.g. `{`, `}`, `[`, `]`, `€`) cost two positions each under strict
     * GSM-7 accounting; treating them as GSM-7-safe here is a deliberate
     * simplification — none of this repo's templates use them.
     */
    private const string GSM7_ALPHABET = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?".
        '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    public static function isGsm7(string $text): bool
    {
        foreach (mb_str_split($text) as $char) {
            if (! str_contains(self::GSM7_ALPHABET, $char)) {
                return false;
            }
        }

        return true;
    }

    public static function segmentCount(string $text): int
    {
        $length = mb_strlen($text);

        if ($length === 0) {
            return 1;
        }

        $perSegment = self::isGsm7($text) ? self::GSM7_CHARS_PER_SEGMENT : self::UNICODE_CHARS_PER_SEGMENT;

        return max(1, (int) ceil($length / $perSegment));
    }
}
