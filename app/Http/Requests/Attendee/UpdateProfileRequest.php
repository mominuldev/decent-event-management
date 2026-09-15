<?php

namespace App\Http\Requests\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Rules\NationalIdNumber;
use App\Domain\Registration\Support\AttendeeIdentity;
use App\Http\Requests\Concerns\NormalisesNationalId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    use NormalisesNationalId;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseNationalIdInput();

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
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'nid_number' => ['nullable', 'string', new NationalIdNumber],
            'occupation' => ['nullable', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
            'tshirt_required' => ['sometimes', 'boolean'],
            'tshirt_size' => ['required_if:tshirt_required,true', 'nullable', 'string', Rule::in(Attendee::TSHIRT_SIZES)],
            // max:80, not max:100: the column is VARCHAR(80), so the longer
            // limit let an over-length district reach MySQL and die there —
            // a 500 where a field-level 422 belongs. The same mismatch the
            // name fields had at max:200 against VARCHAR(150).
            'address_district' => ['nullable', 'string', 'max:80'],
            'current_address' => ['nullable', 'string', 'max:255'],
            'post_office' => ['nullable', 'string', 'max:100'],
            'upazila' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'blood_group' => ['nullable', 'string', Rule::in(Attendee::BLOOD_GROUPS)],
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
