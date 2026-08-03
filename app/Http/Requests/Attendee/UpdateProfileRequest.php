<?php

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:200'],
            'full_name_bn' => ['nullable', 'string', 'max:200'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:254'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'designation' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:200'],
            'tshirt_required' => ['sometimes', 'boolean'],
            'tshirt_size' => ['required_if:tshirt_required,true', 'nullable', 'string', Rule::in(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'])],
            'address_district' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'blood_group' => ['nullable', 'string', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'emergency_contact_name' => ['nullable', 'string', 'max:200'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
