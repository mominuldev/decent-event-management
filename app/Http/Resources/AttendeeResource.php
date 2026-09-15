<?php

namespace App\Http\Resources;

use App\Domain\Registration\Models\Attendee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full attendee record, for a caller entitled to the whole of it.
 *
 * Three audiences share this resource and they are not equally trusted: the
 * admin console, an attendee's own signed-in session — and, through
 * {@see RegistrationResource}, the **unauthenticated**
 * `GET /public/registrations/{ulid}`, which anyone holding a registration
 * ULID may read. `nid_number` is the field where that stops being a
 * theoretical distinction, so it is published to an allowlist of two
 * abilities rather than withheld from a list of callers somebody has to
 * remember to extend — see {@see self::showsNationalId()}.
 *
 * @mixin Attendee
 */
class AttendeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'full_name' => $this->full_name,
            'full_name_bn' => $this->full_name_bn,
            'father_name' => $this->father_name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toISOString(),
            // Absent entirely for a caller outside the allowlist, rather
            // than masked: a partial government ID number is still real
            // digits of one, and the boolean beside it answers the only
            // question the person actually has ("is mine on file?").
            'nid_number' => $this->when(self::showsNationalId($request), fn () => $this->nid_number),
            'nid_number_set' => $this->nid_number !== null && $this->nid_number !== '',
            'occupation' => $this->occupation,
            'designation' => $this->designation,
            'organization' => $this->organization,
            'participant_type' => $this->participant_type,
            'ssc_batch_year' => $this->ssc_batch_year,
            'current_class' => $this->current_class,
            'tshirt_required' => $this->tshirt_required,
            'tshirt_size' => $this->tshirt_size,
            'address_district' => $this->address_district,
            'current_address' => $this->current_address,
            'post_office' => $this->post_office,
            'upazila' => $this->upazila,
            'country' => $this->country,
            'blood_group' => $this->blood_group,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'notes' => $this->notes,
            'is_verified' => $this->is_verified,
            // Deliberately admin-only: PublicAttendeeResource publishes the
            // boolean and nothing about who vouched for it or when.
            'verified_at' => $this->verified_at?->toISOString(),
            // Short-TTL signed URLs (docs/06 §6.4), not the raw private-disk
            // path — `profilePhoto` lazy-loads here if a caller hasn't
            // eager-loaded it, which is fine for a single-resource response.
            'profile_photo_url' => $this->profilePhoto?->temporarySignedUrl(),
            // The rendition an avatar should actually fetch. Falls back to the
            // full-size photo (~1024px, sized for the A5 ticket PDF) only when
            // no derivative exists, so a list never silently pulls hundreds of
            // KB per row once `media:backfill-thumbnails` has run.
            'profile_photo_thumb_url' => $this->profilePhoto?->smallest()->temporarySignedUrl(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Whether this caller may read the attendee's National ID number.
     *
     * An allowlist of exactly two token abilities — staff (`admin`) and the
     * attendee's own signed-in session (`attendee`) — and deliberately not
     * a list of callers to withhold it from. The first draft of this was
     * written the other way round, as "everyone except the passwordless
     * lookup session that existed at the time" (retired 2026-09-14), and it
     * published every registrant's NID on `GET /public/registrations/{ulid}`:
     * that endpoint is unauthenticated, embeds this resource through
     * {@see RegistrationResource}, and so had no token to fail the check. A
     * denylist is only ever as complete as the last person to think about
     * it.
     *
     * The caller it excludes on purpose is the one with **no token at all**
     * — the public registration poll above. Any weaker-than-sign-in
     * credential added later (a token minted on something guessable rather
     * than possessed) belongs on the same side of the line: a government
     * ID number is the number used to *prove* someone is who they say they
     * are elsewhere, not just a detail about them.
     *
     * Checked on the ability rather than on a route or a guard, so a new
     * endpoint returning this resource inherits the rule instead of having
     * to remember it.
     */
    private static function showsNationalId(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            return false;
        }

        return $token->can('admin') || $token->can('attendee');
    }
}
