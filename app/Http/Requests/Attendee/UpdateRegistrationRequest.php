<?php

namespace App\Http\Requests\Attendee;

use App\Domain\Registration\Models\Registration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Registration|null $registration */
        $registration = $this->route('registration');

        if (! $registration) {
            return false;
        }

        return $registration->attendee_id === auth('attendee')->id()
            && in_array($registration->status, ['draft', 'pending_payment'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comments' => ['nullable', 'string', 'max:1000'],
            'special_notes' => ['nullable', 'string', 'max:1000'],
            'guests' => ['nullable', 'array'],
            'guests.*.full_name' => ['required', 'string', 'max:200'],
            'guests.*.relation' => ['required', 'string'],
            'guests.*.age_group' => ['required', 'string', Rule::in(['adult', 'child'])],
            'guests.*.tshirt_required' => ['nullable', 'boolean'],
            'guests.*.tshirt_size' => ['nullable', 'string', 'max:10'],
        ];
    }
}
