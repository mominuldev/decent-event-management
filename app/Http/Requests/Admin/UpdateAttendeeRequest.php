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
            'participant_type' => ['sometimes', 'string', Rule::in(['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'guest', 'sponsor', 'other'])],
            'ssc_batch_year' => ['nullable', 'integer', 'min:1971', 'max:'.max(2026, (int) date('Y'))],
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
        ];
    }
}
