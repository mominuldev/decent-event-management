<?php

namespace App\Domain\Registration\Support;

use App\Domain\Registration\Models\Attendee;

/**
 * The single definition of how an attendee's two unique identifiers —
 * mobile number and email address — are normalised before they are
 * compared or stored.
 *
 * This exists because uniqueness is only as good as the normalisation
 * behind it: `+8801711223344`, `+880 1711-223344` and `01711223344` are one
 * person to a human and three different rows to a `UNIQUE` index. The
 * public registration path has always normalised mobile numbers inline
 * (ADR-08 dedupes attendees on the normalised number); the admin and
 * self-service paths did not, so an admin edit could quietly create the
 * duplicate the public path was built to prevent.
 *
 * Every write path — Action, FormRequest, seeder — goes through here.
 */
final class AttendeeIdentity
{
    /**
     * Strip everything that is not a digit or a leading plus.
     *
     * Deliberately does *not* try to canonicalise a Bangladeshi number into
     * E.164 (`01711…` → `+8801711…`): that guess is wrong for the overseas
     * alumni this event expects, and silently rewriting someone's number is
     * worse than storing what they typed. It only removes formatting.
     */
    public static function normaliseMobile(?string $mobile): string
    {
        return (string) preg_replace('/[^0-9+]/', '', (string) $mobile);
    }

    /**
     * Trim and lowercase, and treat a blank string as "no email".
     *
     * The blank-to-null step is load-bearing, not tidiness: `email` is
     * nullable and a `UNIQUE` index in MySQL permits many NULLs but only
     * one empty string, so a form that posts `""` for an omitted email
     * would let the first attendee through and reject every one after.
     */
    public static function normaliseEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * Every stored form a typed-in mobile number might be sitting under,
     * for a *lookup* — never for a write.
     *
     * {@see self::normaliseMobile()} deliberately does not canonicalise
     * `01711…` into `+8801711…`, because that guess is wrong for an
     * overseas alumnus and this is the value a uniqueness constraint is
     * built on. Signing in is the opposite problem: nobody types `+880`
     * into a login box on their own phone, and refusing them because of it
     * is a support call. So the guess is made here, where being wrong
     * costs one failed match rather than a corrupted row, and both forms
     * are offered to the query.
     *
     * @return array<int, string>
     */
    public static function mobileLookupCandidates(?string $number): array
    {
        $normalised = self::normaliseMobile($number);

        // Nothing dialable was typed. An empty candidate list makes the
        // caller's `whereIn` match no rows, which is the right answer —
        // where a `where('mobile', '')` would match any row that somehow
        // stored a blank.
        if ($normalised === '') {
            return [];
        }

        $candidates = [$normalised];
        $digits = ltrim($normalised, '+');

        // 01XXXXXXXXX -> +8801XXXXXXXXX, and back the other way, so a
        // number stored in either form is found from either form.
        if (preg_match('/^01\d{9}$/', $digits) === 1) {
            $candidates[] = '+880'.substr($digits, 1);
            $candidates[] = '880'.substr($digits, 1);
        } elseif (preg_match('/^8801\d{9}$/', $digits) === 1) {
            $candidates[] = '+'.$digits;
            $candidates[] = '0'.substr($digits, 3);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * The one attendee a sign-in identifier names, or null.
     *
     * Exactly one of the two is expected; `mobile` wins if both arrive, so
     * a caller cannot widen the search by sending a second field. Mobile
     * goes through {@see self::mobileLookupCandidates()} for the reasons
     * given there; email is matched on the normalised value, which is what
     * the unique index is built on, so it can only ever name one row.
     *
     * Soft-deleted attendees are excluded: a removed account must not be
     * signed into, and `withTrashed()` here would do exactly that.
     */
    public static function resolveAttendee(?string $mobile, ?string $email): ?Attendee
    {
        $candidates = self::mobileLookupCandidates($mobile);

        if ($candidates !== []) {
            return Attendee::whereIn('mobile', $candidates)->first();
        }

        $normalisedEmail = self::normaliseEmail($email);

        if ($normalisedEmail === null) {
            return null;
        }

        return Attendee::where('email', $normalisedEmail)->first();
    }
}
