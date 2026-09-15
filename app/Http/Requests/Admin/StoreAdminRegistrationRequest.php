<?php

namespace App\Http\Requests\Admin;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Rules\NationalIdNumber;
use App\Domain\Registration\Support\RegistrationContext;
use App\Http\Requests\Concerns\NormalisesNationalId;
use App\Http\Requests\Public\StoreRegistrationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A registration typed in by staff at a desk.
 *
 * Deliberately a mirror of {@see StoreRegistrationRequest} rather than a
 * subclass of it: the two differ in three ways that a subclass would have
 * to unpick one rule at a time, and a rule silently inherited into the
 * admin path is how the public path's `password` handling would end up
 * reachable from an authenticated endpoint. What differs:
 *
 *  - **No `password`.** Staff must never set an attendee's sign-in
 *    credential; the attendee sets their own through the sign-in flow,
 *    which proves possession of the phone first.
 *  - **No `payment_method`.** A counter sale is always cash, and the
 *    payment row is built from {@see RegistrationContext::counter()}
 *    rather than from anything the caller sends.
 *  - **`idempotency_key` comes from the `Idempotency-Key` header**, not the
 *    body, because the route carries the `idempotent` middleware.
 *
 * Everything else — including every profile field the public form requires
 * — is identical, so a record taken at a desk is as complete as one taken
 * online and prints correctly on the ticket and in the directory PDF. That
 * mirroring is the reason the postal address and date of birth are
 * `required` here too: a counter sale that could skip them would quietly
 * become the way incomplete records get into the directory.
 */
class StoreAdminRegistrationRequest extends FormRequest
{
    use NormalisesNationalId;

    public function authorize(): bool
    {
        return $this->user()?->can('registration.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseNationalIdInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // max:150 on both, matching the VARCHAR(150) columns — the
            // longer limit the public request once carried turned an
            // over-length name into a 500 rather than a field-level 422.
            'full_name' => ['required', 'string', 'max:150'],
            // Optional since 2026-09-13, matching the public form, which no
            // longer asks for it.
            'full_name_bn' => ['nullable', 'string', 'max:150'],
            'father_name' => ['required', 'string', 'max:150'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:254'],
            'gender' => ['required', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'nid_number' => ['nullable', 'string', new NationalIdNumber],
            'blood_group' => ['nullable', 'string', Rule::in(Attendee::BLOOD_GROUPS)],
            'occupation' => ['required', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
            'current_address' => ['required', 'string', 'max:255'],
            'post_office' => ['required', 'string', 'max:100'],
            'upazila' => ['required', 'string', 'max:100'],
            'address_district' => ['required', 'string', 'max:80'],
            'participant_type' => ['required', 'string', Rule::in(['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'guest', 'sponsor', 'other'])],
            // Required of a former student only. A current student has not
            // sat SSC yet, so their "batch" is at best an expected year — a
            // value they may give but must not be refused for lacking
            // (2026-09-13). Everyone else has no batch at all.
            'ssc_batch_year' => ['required_if:participant_type,former_student', 'nullable', 'integer', 'min:1971', 'max:'.date('Y')],
            // Required of a current student, as the batch year is of a
            // former one: it is the one fact that places them in the school.
            'current_class' => ['required_if:participant_type,current_student', 'nullable', 'string', Rule::in(Attendee::CURRENT_CLASSES)],
            'ticket_type_ulid' => ['required', 'string', Rule::exists('ticket_types', 'ulid')],
            'event_session_ulid' => ['nullable', 'string', Rule::exists('event_sessions', 'ulid')],
            'participation_type' => ['required', 'string', Rule::in(['single', 'couple', 'family'])],
            'adults_count' => ['required', 'integer', 'min:1', 'max:10'],
            // Every child attending, infants included. Which of them are
            // free is decided server-side from the guests' own ages — see
            // CreateRegistration::countFreeInfants().
            'children_count' => ['required', 'integer', 'min:0', 'max:10'],
            'guests' => ['nullable', 'array'],
            'guests.*.full_name' => ['required', 'string', 'max:200'],
            'guests.*.relation' => ['required', 'string', Rule::in(['spouse', 'child', 'parent', 'sibling', 'other'])],
            'guests.*.age_group' => ['required', 'string', Rule::in(['adult', 'child'])],
            'guests.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'guests.*.gender' => ['nullable', 'string', Rule::in(['male', 'female'])],
            'guests.*.tshirt_required' => ['nullable', 'boolean'],
            'guests.*.tshirt_size' => ['required_if:guests.*.tshirt_required,true', 'nullable', 'string', Rule::in(Attendee::TSHIRT_SIZES)],
            'tshirt_required' => ['nullable', 'boolean'],
            'tshirt_size' => ['required_if:tshirt_required,true', 'nullable', 'string', Rule::in(Attendee::TSHIRT_SIZES)],
            'special_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
