<?php

namespace App\Http\Requests\Admin;

use App\Domain\CheckIn\Models\VolunteerProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $volunteer = $this->route('volunteer');
        $userId = $volunteer instanceof VolunteerProfile ? $volunteer->user_id : null;

        return [
            // The person: these live on the linked users row. Lengths match the
            // columns (users.name is VARCHAR(150)) so an over-long value is a
            // field-level 422 rather than a database error.
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            // Optional reset. Blank means "leave it alone", never "set it blank".
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],

            // The assignment: these live on volunteer_profiles.
            'team' => ['sometimes', 'nullable', 'string', 'max:64'],
            'shift_starts_at' => ['sometimes', 'nullable', 'date'],
            'shift_ends_at' => ['sometimes', 'nullable', 'date', 'after:shift_starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
