<?php

namespace App\Http\Requests\Admin;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Rules\NationalIdNumber;
use App\Domain\Registration\Support\AttendeeIdentity;
use App\Http\Requests\Concerns\NormalisesNationalId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendeeRequest extends FormRequest
{
    use NormalisesNationalId;

    public function authorize(): bool
    {
        return $this->user()?->can('attendee.update') ?? false;
    }

    /**
     * Both identifiers are compared in normalised form, so an edit cannot
     * slip past the unique check on formatting alone and then land on the
     * database constraint as a 500.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseNationalIdInput();

        if ($this->has('mobile')) {
            $this->merge(['mobile' => AttendeeIdentity::normaliseMobile($this->input('mobile'))]);
        }

        if ($this->has('email')) {
            $this->merge(['email' => AttendeeIdentity::normaliseEmail($this->input('email'))]);
        }

        if ($this->has('whatsapp_number')) {
            $this->merge(['whatsapp_number' => AttendeeIdentity::normaliseMobile($this->input('whatsapp_number')) ?: null]);
        }

        // ISO 3166-1 alpha-2, as the CHAR(2) column holds it. Uppercased
        // here so `bd` is accepted rather than refused on case alone.
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $attendee = $this->route('attendee');
        $attendeeId = $attendee instanceof Attendee ? $attendee->getKey() : null;

        return [
            // max:150 matches the VARCHAR(150) columns — see
            // StoreRegistrationRequest for why the old 200 was a 500 waiting
            // to happen.
            'full_name' => ['sometimes', 'string', 'max:150'],
            'full_name_bn' => ['nullable', 'string', 'max:150'],
            // Nullable, not required, even though the public form demands
            // all three: an admin corrects records that predate the fields
            // and creates none, so requiring them here would make every
            // unrelated edit to a legacy attendee impossible to save.
            'father_name' => ['nullable', 'string', 'max:150'],
            'occupation' => ['nullable', 'string', 'max:100'],
            // The whole postal address, plus the three identity details the
            // public form now collects. Nullable for the same reason the
            // 2026-08-16 fields are — an admin corrects records that predate
            // them — but present, because a field the form requires and the
            // console cannot fix is a record only a DBA can repair.
            'current_address' => ['nullable', 'string', 'max:255'],
            'post_office' => ['nullable', 'string', 'max:100'],
            'upazila' => ['nullable', 'string', 'max:100'],
            // max:80, matching the column. Anything longer was a 500.
            'address_district' => ['nullable', 'string', 'max:80'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'nid_number' => ['nullable', 'string', new NationalIdNumber],
            'blood_group' => ['nullable', 'string', Rule::in(Attendee::BLOOD_GROUPS)],
            // Not `withoutTrashed()`: the database constraint covers
            // soft-deleted rows, so the validator must too or the 422 turns
            // back into a 500 the moment the conflict is with a deleted
            // attendee.
            'mobile' => ['sometimes', 'string', 'max:20', Rule::unique('attendees', 'mobile')->ignore($attendeeId)],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('attendees', 'email')->ignore($attendeeId)],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'participant_type' => ['sometimes', 'string', Rule::in(['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'guest', 'sponsor', 'other'])],
            'ssc_batch_year' => ['nullable', 'integer', 'min:1971', 'max:'.max(2026, (int) date('Y'))],
            // Nullable rather than required_if: an admin corrects legacy
            // records, and one holding free text here must stay editable
            // in every other field. The console omits the key when the
            // recorded value is not in the catalogue.
            'current_class' => ['nullable', 'string', Rule::in(Attendee::CURRENT_CLASSES)],
            'current_section' => ['nullable', 'string', 'max:32'],
            'current_roll' => ['nullable', 'string', 'max:16'],
            // The rest of what the attendee can edit on their own profile
            // page, so the console can correct anything the person can —
            // a field only its owner may fix is a support call waiting to
            // happen. Same limits as UpdateProfileRequest.
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
            'tshirt_required' => ['sometimes', 'boolean'],
            'tshirt_size' => ['required_if:tshirt_required,true', 'nullable', 'string', Rule::in(Attendee::TSHIRT_SIZES)],
            // `attendees.country` is CHAR(2) NOT NULL — a two-letter code
            // and never a name, or the save dies in MySQL rather than here.
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'emergency_contact_name' => ['nullable', 'string', 'max:200'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'is_verified' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile.unique' => 'This mobile number already belongs to another attendee.',
            'email.unique' => 'This email address already belongs to another attendee.',
            'tshirt_size.required_if' => 'Pick a size when a T-shirt is requested.',
            'country.regex' => 'Country must be a two-letter ISO code, such as BD.',
        ];
    }
}
