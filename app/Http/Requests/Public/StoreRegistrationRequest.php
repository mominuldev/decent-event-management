<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'full_name' => ['required', 'string', 'max:200'],
            'full_name_bn' => ['nullable', 'string', 'max:200'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:254'],
            'gender' => ['required', 'string', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
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
            'comments' => ['nullable', 'string', 'max:1000'],
            'special_notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
