<?php

namespace App\Http\Resources\Public;

use App\Domain\Registration\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * One card in the public attendees directory.
 *
 * This is the narrowest resource in the codebase and the allowlist rule
 * (CLAUDE.md — "explicit field allowlists, never blocklists") matters most
 * here, because the audience is *everyone on the internet*, not a staff
 * member behind an audited session. Deliberately absent, and not to be added
 * without someone deciding it is really meant to be public:
 *
 *  - `mobile`, `email`, `whatsapp_number`, `emergency_contact_*` — contact
 *    details. A public roster of alumni names next to their phone numbers is
 *    a scraping target and a directory the person never consented to.
 *  - `father_name`, `current_address`, `date_of_birth`, `gender`,
 *    `blood_group`, `notes` — personal record, collected to run the event.
 *    `gender` stays private even though the card draws a gendered placeholder:
 *    what is published is `avatar_variant`, a rendering hint, and the two are
 *    not the same field. See {@see avatarVariant()}.
 *  - The guest roster. A registration's family members are named people who
 *    never filled a form in; the card shows *how many* came, never who.
 *  - Money, `registration_number`, and payment state. The directory says a
 *    person is coming, not what they paid.
 *
 * The badge photo *is* published, by explicit decision (2026-08-21) — the card
 * shows it and falls back to initials. Two consequences worth knowing before
 * touching it:
 *
 *  - It is the 128px thumbnail (`smallest()`), never the ~1024px original the
 *    ticket PDF prints. Twelve cards of the full-size rendition would be
 *    several megabytes, and the original is a higher-resolution photograph of
 *    a private individual than a directory tile needs.
 *  - The URL is minted with `cacheableSignedUrl()`, not `temporarySignedUrl()`.
 *    A per-request expiry would change the body on every request, so the
 *    endpoint's ETag would never match and its 304 path would be dead — see
 *    that method's docblock.
 *
 * A signed URL handed to an anonymous caller can be copied and re-shared for
 * the life of the signature. That is inherent in publishing the photos at all,
 * not a defect in this resource; the way to undo it is to stop returning the
 * field, not to shorten the TTL.
 *
 * @mixin Registration
 */
class PublicAttendeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attendee = $this->attendee;

        if ($attendee === null) {
            // Unreachable through PublicAttendeeDirectory: `attendee_id` is
            // NOT NULL and the directory inner-joins `attendees`. Kept as a
            // throw rather than a null-safe read so a future caller that
            // assembles its own query fails loudly, instead of publishing a
            // card with no name on it.
            throw new LogicException("Registration {$this->ulid} has no attendee to publish.");
        }

        return [
            // The registration's ULID, not the attendee's: this row is one
            // booking, and the attendee id is not the public handle for a
            // person who may hold more than one.
            'ulid' => $this->ulid,

            'full_name' => $attendee->full_name,
            'full_name_bn' => $attendee->full_name_bn,
            'participant_type' => $attendee->participant_type,
            'ssc_batch_year' => $attendee->ssc_batch_year !== null ? (int) $attendee->ssc_batch_year : null,
            'current_class' => $attendee->current_class,
            'occupation' => $attendee->occupation,
            'designation' => $attendee->designation,
            'organization' => $attendee->organization,
            'address_district' => $attendee->address_district,
            'country' => $attendee->country,
            'is_verified' => (bool) $attendee->is_verified,

            // Null for the many attendees who never uploaded one — the card
            // draws a placeholder rather than a broken image.
            'profile_photo_url' => $attendee->profilePhoto?->smallest()->cacheableSignedUrl(),
            'avatar_variant' => self::avatarVariant($attendee->gender),

            // Party makeup — counts only, never the people.
            'participation_type' => $this->participation_type,
            'adults_count' => (int) $this->adults_count,
            'children_count' => (int) $this->children_count,
            'infants_count' => (int) $this->infants_count,
            'guests_count' => (int) ($this->guests_count ?? 0),

            'ticket_type_name' => $this->ticketType?->name,
            'ticket_type_name_bn' => $this->ticketType?->name_bn,

            'registered_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Which placeholder the card should draw when an attendee has no photo.
     *
     * Deliberately a derived hint rather than the `gender` column itself.
     * `attendees.gender` is VARCHAR(32) precisely so it can hold
     * `prefer_not_to_say` — a value whose whole meaning is "do not publish
     * this" — and it also carries `other`, plus null for every row that
     * predates the field being asked for. Publishing the raw column would put
     * all of that on an anonymous endpoint; mapping it here publishes only
     * what the card needs to pick an outline.
     *
     * Anything that is not plainly `male` or `female` collapses to `neutral`,
     * so the placeholder is never a guess about somebody. That is not an edge
     * case to wave through: only `StoreRegistrationRequest` constrains this
     * column to male/female, so seeded, imported and admin-created attendees
     * genuinely land outside it.
     */
    private static function avatarVariant(?string $gender): string
    {
        return match ($gender) {
            'male', 'female' => $gender,
            default => 'neutral',
        };
    }
}
