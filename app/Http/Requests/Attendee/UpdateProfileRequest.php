<?php

namespace App\Http\Requests\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Support\AttendeeIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => AttendeeIdentity::normaliseEmail($this->input('email'))]);
        }

        if ($this->has('whatsapp_number')) {
            $this->merge(['whatsapp_number' => AttendeeIdentity::normaliseMobile($this->input('whatsapp_number')) ?: null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $attendee = $this->user();
        $attendeeId = $attendee instanceof Attendee ? $attendee->getKey() : null;

        return [
            'full_name' => ['sometimes', 'string', 'max:150'],
            'full_name_bn' => ['nullable', 'string', 'max:150'],
            // Not unique: a household may legitimately share one WhatsApp
            // number, and nothing identifies an attendee by it. Only
            // `mobile` and `email` are identifiers — and `mobile` is not
            // self-editable at all, since it is the login channel.
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('attendees', 'email')->ignore($attendeeId)],
            'father_name' => ['nullable', 'string', 'max:150'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
            'tshirt_required' => ['sometimes', 'boolean'],
            'tshirt_size' => ['required_if:tshirt_required,true', 'nullable', 'string', Rule::in(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'])],
            'address_district' => ['nullable', 'string', 'max:100'],
            'current_address' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:100'],
            'blood_group' => ['nullable', 'string', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'emergency_contact_name' => ['nullable', 'string', 'max:200'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered to another attendee.',
        ];
    }
}
