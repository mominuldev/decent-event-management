<?php

namespace App\Http\Requests\Admin;

use App\Domain\Registration\Support\RegistrationContext;
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
 * Everything else — including all four of the profile fields the public
 * form requires — is identical, so a record taken at a desk is as complete
 * as one taken online and prints correctly on the ticket and in the
 * directory PDF.
 */
class StoreAdminRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('registration.create') ?? false;
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
            'full_name_bn' => ['required', 'string', 'max:150'],
            'father_name' => ['required', 'string', 'max:150'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:254'],
            'gender' => ['required', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date'],
            'occupation' => ['required', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
            'current_address' => ['required', 'string', 'max:255'],
            'participant_type' => ['required', 'string', Rule::in(['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'guest', 'sponsor', 'other'])],
            'ssc_batch_year' => ['required_if:participant_type,current_student,former_student', 'nullable', 'integer', 'min:1971', 'max:'.date('Y')],
            'current_class' => ['nullable', 'string', 'max:50'],
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
            'guests.*.tshirt_size' => ['required_if:guests.*.tshirt_required,true', 'nullable', 'string', Rule::in(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'])],
            'tshirt_required' => ['nullable', 'boolean'],
            'tshirt_size' => ['required_if:tshirt_required,true', 'nullable', 'string', Rule::in(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'])],
            'special_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
