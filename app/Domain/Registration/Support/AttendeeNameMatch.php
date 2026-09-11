<?php

namespace App\Domain\Registration\Support;

use App\Domain\Registration\Models\Attendee;

/**
 * Does the name somebody typed match the name on an attendee record?
 *
 * This exists for one caller — the passwordless "find my ticket" lookup —
 * and it is worth being precise about what it is: **the name is the whole
 * secret there.** Sign-in by mobile plus password, or mobile plus a code
 * sent to that handset, both prove possession of something. Mobile plus
 * name proves only that the caller knows two facts about somebody, and a
 * name is not a secret in any ordinary sense. The product owner made that
 * trade deliberately (an alumni reunion whose attendees are largely elderly,
 * where an OTP round trip loses people); what the code can do is make the
 * comparison neither looser nor tighter than it has to be.
 *
 * So: normalised equality, never a prefix, a first name, or a fuzzy
 * distance. Each of those would widen an already-narrow secret — "Rahim"
 * matching "Rahim Uddin" would turn a guessable given name into a working
 * credential. The cost is that `Md. Rahim` does not open a record stored as
 * `Mohammad Rahim`; that is a support call, and a support call is the
 * correct outcome for a caller who cannot state the registered name.
 */
final class AttendeeNameMatch
{
    /**
     * Fold away everything that is presentation rather than name.
     *
     * Case, punctuation (`Md.` and `Md` are one name), and runs of
     * whitespace. `\p{M}` is in the keep-set because Bengali carries a
     * large part of its content in combining marks — dropping matras would
     * collapse genuinely different names onto each other, which is the one
     * direction this must never fail in.
     */
    public static function normalise(?string $name): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', (string) $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return mb_strtolower(trim($name));
    }

    /**
     * Either recorded name is accepted — Latin or Bangla.
     *
     * A registrant who typed `রহিম উদ্দিন` into the Bangla field and
     * `Rahim Uddin` into the Latin one should be able to open their record
     * with whichever one comes to mind, and on a Bengali keyboard the
     * Bangla one usually will. This is not a widening of the secret: both
     * values name the same person and were both supplied by them.
     *
     * `hash_equals` rather than `===` so the comparison does not return
     * early on the first differing byte. The timing signal is small, but
     * this is a guessing surface by construction and the call costs
     * nothing.
     */
    public static function matches(Attendee $attendee, ?string $typed): bool
    {
        $typed = self::normalise($typed);

        // An empty typed name must never match, including against an
        // attendee row whose own name is somehow blank — which would
        // otherwise turn "leave the name box empty" into a skeleton key.
        if ($typed === '') {
            return false;
        }

        foreach ([$attendee->full_name, $attendee->full_name_bn] as $stored) {
            $stored = self::normalise($stored);

            if ($stored !== '' && hash_equals($stored, $typed)) {
                return true;
            }
        }

        return false;
    }
}
