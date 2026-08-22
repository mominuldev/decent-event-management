<?php

namespace App\Http\Requests\Public;

use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'full_name_bn' => ['required', 'string', 'max:150'],
            // Required here but nullable in the column, deliberately: this is
            // a rule about what the public form may submit, not a claim that
            // every attendee row already carries one. Attendees created
            // before this — and by an admin, who edits rather than registers
            // — legitimately have neither.
            'father_name' => ['required', 'string', 'max:150'],
            'mobile' => ['required', 'string', 'max:20'],

            // The sign-in password, chosen at checkout so an attendee never
            // needs an SMS to reach their own registration. `nullable`
            // rather than `required`: this endpoint is also how an admin
            // tool or an import creates a registration, and a returning
            // registrant already has one — see
            // `CreateRegistration::setInitialPassword()`, which is what
            // decides whether the value is used at all. Confirmation is
            // checked here rather than only in the browser so a non-browser
            // client cannot set a password its user mistyped.
            'password' => ['nullable', 'string', Password::min(8), 'confirmed'],
            'email' => ['nullable', 'email', 'max:254'],
            'gender' => ['required', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date'],
            'occupation' => ['required', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
            // One free-text line, not a structured address block — see the
            // migration for why.
            'current_address' => ['required', 'string', 'max:255'],
            'participant_type' => ['required', 'string', Rule::in(['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'guest', 'sponsor', 'other'])],
            'ssc_batch_year' => ['required_if:participant_type,current_student,former_student', 'nullable', 'integer', 'min:1971', 'max:'.date('Y')],
            'current_class' => ['nullable', 'string', 'max:50'],
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
            'guests.*.tshirt_size' => ['required_if:guests.*.tshirt_required,true', 'nullable', 'string', Rule::in(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'])],
            'tshirt_required' => ['nullable', 'boolean'],
            'tshirt_size' => ['required_if:tshirt_required,true', 'nullable', 'string', Rule::in(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'])],
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
