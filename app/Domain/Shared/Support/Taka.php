<?php

namespace App\Domain\Shared\Support;

/**
 * Integer paisa rendered as taka, for the one place it is allowed to happen:
 * the boundary where a number leaves the system for a human or a spreadsheet.
 *
 * Money is `BIGINT UNSIGNED` paisa everywhere inside this codebase and must
 * stay that way — this class does not weaken that rule, it marks its edge.
 * Nothing may parse these strings back into an amount; a value that came out
 * of here is display, not data.
 *
 * `intdiv` and modulo rather than `$paisa / 100`, which is the construction
 * already repeated in five listeners: division produces a float, and a float
 * is the representation this codebase spends real effort keeping money out
 * of. The sign is handled explicitly because `intdiv(-250050, 100)` is -2500
 * and `-250050 % 100` is -50, which naive concatenation renders as the
 * nonsense "-2500.-50" — reachable here, since a day of nothing but refunds
 * has a negative net.
 */
final class Taka
{
    /** `-2500.50` — no thousands separator, safe for a CSV cell. */
    public static function plain(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $abs = abs($paisa);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** `৳-2,500.50` — for a document a person reads rather than sums. */
    public static function display(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $abs = abs($paisa);

        return '৳'.$sign.number_format(intdiv($abs, 100)).'.'.sprintf('%02d', $abs % 100);
    }

    /**
     * The value for a spreadsheet cell the reader will SUM().
     *
     * A float, deliberately: a string would land in the sheet as text and
     * every total the operator builds on it would silently come out as zero.
     * Exact for every amount this system can hold — a float64 represents
     * hundredths without loss far past `PHP_INT_MAX` paisa.
     */
    public static function numeric(int $paisa): float
    {
        return $paisa / 100;
    }
}
