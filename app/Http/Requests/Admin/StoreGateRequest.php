<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gate.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', Rule::unique('gates', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'event_session_ulid' => ['nullable', 'string', Rule::exists('event_sessions', 'ulid')],
            'allowed_ticket_type_ulids' => ['nullable', 'array'],
            'allowed_ticket_type_ulids.*' => ['string', Rule::exists('ticket_types', 'ulid')],
            'location_note' => ['nullable', 'string', 'max:190'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
