<?php

namespace App\Http\Requests\Public;

use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Rules\NationalIdNumber;
use App\Http\Requests\Concerns\NormalisesNationalId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreRegistrationRequest extends FormRequest
{
    use NormalisesNationalId;

    public function authorize(): bool
    {
        return true;
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
            // max:150, not max:200: both columns are VARCHAR(150), so the
            // longer limit turned an over-length name into a database error
            // (a 500) instead of a field-level 422.
            'full_name' => ['required', 'string', 'max:150'],
            // No longer asked for on the registration form (2026-09-13).
            // Still accepted so a cached build of the public site that sends
            // it keeps working, and so an admin import can supply one; the
            // ticket PDF, the Bangla email greeting and the directory card
            // all fall back to `full_name` when it is absent.
            'full_name_bn' => ['nullable', 'string', 'max:150'],
            // Required here but nullable in the column, deliberately: this is
            // a rule about what the public form may submit, not a claim that
            // every attendee row already carries one. Attendees created
            // before this — and by an admin, who edits rather than registers
            // — legitimately have neither.
            'father_name' => ['required', 'string', 'max:150'],
            'mobile' => ['required', 'string', 'max:20'],

            // The sign-in password, chosen at checkout: signing in with it is
            // how an attendee views and updates their own details afterwards
            // (2026-09-14 — the passwordless name-plus-number lookup that
            // briefly replaced it is gone). `required`, not `nullable`, so a
            // self-registered attendee can never end up with no way in but
            // a paid SMS. Admin desk registrations go through
            // StoreAdminRegistrationRequest and are not affected. A
            // returning registrant already has one — see
            // `CreateRegistration::setInitialPassword()`, which is what
            // decides whether the value is used at all. Confirmation is
            // checked here rather than only in the browser so a non-browser
            // client cannot set a password its user mistyped.
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
            'email' => ['nullable', 'email', 'max:254'],
            'gender' => ['required', 'string', Rule::in(['male', 'female'])],
            // Required as of 2026-09-13, where it was `nullable` before and
            // no form ever sent it. `before:today` rather than a minimum
            // age: the registrant may be a current student of any age, so
            // the only impossible answer is one in the future. The 1900
            // floor is a typo guard — a mistyped century is otherwise
            // stored without complaint and prints on the directory.
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            // Optional, unlike the address fields beside it. An NID *or* a
            // birth registration number — an under-18 current student has
            // no NID at all, only a birth certificate — and an alumnus
            // registering from a phone will not have either to hand, so
            // refusing the whole registration over it would cost more than
            // the field is worth. Already reduced to digits by
            // prepareForValidation().
            'nid_number' => ['nullable', 'string', new NationalIdNumber],
            'blood_group' => ['nullable', 'string', Rule::in(Attendee::BLOOD_GROUPS)],
            'occupation' => ['required', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
            // Four free-text lines read in the order a Bangladeshi address is
            // written — village/road, post office, upazila, district. None of
            // them is a lookup against a reference table; see the
            // 2026-09-13 migration for why.
            //
            // `max:80` on the district matches its VARCHAR(80) column. The
            // self-service profile validated it at 100 until 2026-09-13,
            // which is the same 500-waiting-to-happen the name fields had
            // at max:200 against VARCHAR(150).
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
            // Was never validated *and* never in the rules at all, so
            // `validated()` stripped it and every registration silently fell
            // back to the default gateway (part of D7). Allowlisted against
            // the resolver's own list so a payment row can never carry a
            // method nothing can build an adapter for.
            'payment_method' => ['nullable', 'string', Rule::in(PaymentGatewayResolver::SUPPORTED_GATEWAYS)],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
