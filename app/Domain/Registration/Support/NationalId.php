<?php

namespace App\Domain\Registration\Support;

use App\Domain\Notification\Support\Msisdn;
use App\Domain\Registration\Rules\NationalIdNumber;

/**
 * The single definition of what goes in the `nid_number` column — a
 * Bangladeshi National ID number **or a birth registration number** — and
 * how one is stored.
 *
 * One field for both, since 2026-09-13: an under-18 current student has no
 * NID, but they do have a birth certificate, and the number on it is the
 * only government identifier they can offer. The column keeps its `nid_number`
 * name (and the API field with it) because both frontends and every write
 * path already carry it; the label is what changed.
 *
 * Deliberately not part of {@see AttendeeIdentity}, which is explicitly about
 * the two identifiers an attendee is *deduplicated* on. An NID is an
 * identifier of a person, but this system never resolves an attendee by one
 * and there is no unique index behind it — see the migration for why — so
 * folding it in would make that class's docblock false. Same call the SMS
 * work made when it put {@see Msisdn} beside
 * `AttendeeIdentity` rather than inside it.
 *
 * Bangladesh has issued NID numbers in exactly three widths:
 *
 *  - **17 digits** — the oldest form, birth-registration derived (a 4-digit
 *    birth year followed by 13 digits).
 *  - **13 digits** — the older card number, the same value with the birth
 *    year dropped.
 *  - **10 digits** — the smart card (NIDW) number issued today.
 *
 * A birth registration number (BDRIS) is **17 digits** in its online form —
 * the same 4-digit year + 13 shape the old NID inherited — and **16 digits**
 * on certificates issued before 2013, which BDRIS itself tells the holder to
 * pad with a `0` after the year. The 16-digit form is accepted as typed
 * rather than padded here: this system does not look the number up
 * anywhere, and rewriting a government identifier on the way in would store
 * a value that no longer matches the paper in the person's hand.
 *
 * Nothing else is a real number, so those four lengths are what
 * {@see NationalIdNumber} accepts. Being
 * strict is affordable precisely because the field is optional: the cost of
 * refusing an unusual value is that somebody leaves the box empty, where the
 * cost of accepting anything is a directory full of typos in the one field
 * that exists to confirm an alumnus is who they say they are.
 */
final class NationalId
{
    /**
     * The three widths a real NID number has (10, 13, 17) plus the pre-2013
     * 16-digit birth registration number.
     *
     * @var list<int>
     */
    public const LENGTHS = [10, 13, 16, 17];

    /**
     * Digits only, with a blank result treated as "no number given".
     *
     * People write an NID with spaces (`1234 5678 90`), dashes, or a
     * `NID:` prefix copied off a card, and a birth registration number is
     * printed in spaced groups on the certificate. Storing the punctuation would mean
     * the same number stored several ways, so it is stripped on the way in
     * and the validator runs against the normalised value — otherwise a
     * correctly-typed spaced number would be refused for being 12
     * characters long.
     *
     * Blank becomes null rather than `''` so the column records "not
     * known" once, in one shape. Nothing depends on that the way the
     * blank-email rule depends on it (there is no unique index here), but a
     * column holding both NULL and `''` for the same fact is a filter
     * everyone gets wrong exactly once.
     */
    public static function normalise(?string $nid): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $nid) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * Whether an already-normalised value is one of the real widths.
     */
    public static function isWellFormed(?string $nid): bool
    {
        return $nid !== null && in_array(strlen($nid), self::LENGTHS, true);
    }
}
