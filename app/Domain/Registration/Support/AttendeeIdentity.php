<?php

namespace App\Domain\Registration\Support;

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
}
