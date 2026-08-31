<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    /**
     * Twelve, not the eight an attendee gets. A staff account reaches other
     * people's contact details, money and the gate; the same figure
     * admin:create-super-admin enforces, so the two ways a staff password can
     * be set cannot disagree about what is acceptable.
     */
    public const int MIN_LENGTH = 12;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Checked against the stored hash in the controller rather than by
            // the framework's `current_password` rule, which calls
            // Hash::check() directly and therefore throws — rather than
            // failing validation — when the stored value is not readable by
            // the configured hasher. See App\Domain\Shared\Support\PasswordHash.
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(self::MIN_LENGTH)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The two new passwords do not match.',
        ];
    }
}
