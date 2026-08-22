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

    /**
     * Placeholder width used when estimating an unrendered template.
     * Twelve characters is about what a ticket number, a date or a short
     * name comes out at — deliberately not zero, because a template that
     * fits only while its variables are empty is not a template that fits.
     */
    private const int PLACEHOLDER_WIDTH = 12;

    /**
     * A template body with its `{{placeholders}}` replaced, ready to be
     * measured.
     *
     * Measuring the raw body is wrong in a way that is easy to miss and
     * expensive to get wrong: **`{` and `}` are not in the GSM-7 alphabet**,
     * so any template containing a placeholder at all reports as Unicode at
     * 70 characters per segment, when the message it actually sends may be
     * plain ASCII at 160. The seeded ticket confirmation measured three
     * segments raw and one rendered — a 3x error, in the direction that
     * makes a fine message look unaffordable.
     *
     * Values the caller knows are substituted; anything left over becomes a
     * representative-width token, since `{{event_name}}` is usually shorter
     * than what it renders to and would flatter the estimate.
     *
     * @param  array<string, mixed>  $sample
     */
    public static function renderForEstimate(string $body, array $sample = []): string
    {
        foreach ($sample as $key => $value) {
            if (is_scalar($value)) {
                $body = str_replace('{{'.$key.'}}', (string) $value, $body);
            }
        }

        return (string) preg_replace(
            '/\{\{[A-Za-z0-9_.]+\}\}/',
            str_repeat('x', self::PLACEHOLDER_WIDTH),
            $body,
        );
    }
}
