<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVolunteerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('volunteer.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'team' => ['sometimes', 'nullable', 'string', 'max:64'],
            'shift_starts_at' => ['sometimes', 'nullable', 'date'],
            'shift_ends_at' => ['sometimes', 'nullable', 'date', 'after:shift_starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
