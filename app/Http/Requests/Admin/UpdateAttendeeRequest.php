<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('attendee.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:200'],
            'full_name_bn' => ['nullable', 'string', 'max:200'],
            'mobile' => ['sometimes', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:254'],
            'participant_type' => ['sometimes', 'string', Rule::in(['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'other'])],
            'ssc_batch_year' => ['nullable', 'integer'],
            'is_verified' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
