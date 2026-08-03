<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualOverrideCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('checkin.manual_override') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ticket_ulid' => ['required', 'string', Rule::exists('tickets', 'ulid')],
            'gate_ulid' => ['required', 'string', Rule::exists('gates', 'ulid')],
            'party_size' => ['required', 'integer', 'min:1', 'max:20'],
            'reason' => ['required', 'string', 'max:255'],
            'client_scan_uuid' => ['nullable', 'uuid'],
        ];
    }
}
