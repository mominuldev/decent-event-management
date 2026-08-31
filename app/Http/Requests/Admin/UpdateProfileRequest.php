<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Your own account. There is no permission to check because there is
        // no way for this to touch anybody else's.
        return $this->user() !== null;
    }

    /**
     * `users.email` collates utf8mb4_0900_ai_ci, so the unique index already
     * treats Foo@x.com and foo@x.com as one address — storing whichever case
     * was typed only makes the column disagree with itself between rows.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The column widths, so an over-long value is a field error rather
            // than a truncation or a 500 out of MySQL.
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'email',
                'max:190',
                // Deliberately not withoutTrashed(): uk_users_email covers
                // soft-deleted rows, so a validator that ignored them would
                // pass and then hit the constraint raw — a 500 where a 422
                // belongs. Same discipline as the attendee uniqueness rules.
                Rule::unique('users', 'email')->ignore($this->user()?->getKey()),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Another staff account already uses that email address.',
        ];
    }
}
