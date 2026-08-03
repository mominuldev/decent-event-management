<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVolunteerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('volunteer.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'volunteer_code' => ['required', 'string', 'max:16', Rule::unique('volunteer_profiles', 'volunteer_code')],
            'team' => ['nullable', 'string', 'max:64'],
            'shift_starts_at' => ['nullable', 'date'],
            'shift_ends_at' => ['nullable', 'date', 'after:shift_starts_at'],
        ];
    }
}
