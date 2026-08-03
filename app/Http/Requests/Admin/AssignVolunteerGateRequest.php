<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignVolunteerGateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('volunteer.assign_gate') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gate_ulid' => ['required', 'string', Rule::exists('gates', 'ulid')],
            'event_session_ulid' => ['nullable', 'string', Rule::exists('event_sessions', 'ulid')],
        ];
    }
}
